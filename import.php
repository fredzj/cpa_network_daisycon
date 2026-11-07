<?php
declare(strict_types=1);

/**
 * Daisycon API Import
 *
 * Usage:   php import.php [--dry-run] [--skip-products] [--feed-format=csv|json|xml]
 *                         [--media=ID[,ID...]]
 *
 *   --media=ID,ID  Import only these media (and their programs, feeds, products, news).
 *                  Overrides config import.media_ids. Reference data is always imported.
 *
 * Imports in order:
 *   1. Countries, Languages, Locales  (common data)
 *   2. Media / websites               (publisher data)
 *   3. Approved subscriptions         (media → program mapping)
 *   4. Programs / campaigns           (details for each approved program)
 *   5. Product feeds (productfeed_v2) (per program)
 *   6. Products                       (per feed, fully paginated)
 *
 * All upserts use ON DUPLICATE KEY UPDATE — safe to re-run at any time.
 */

// ═══════════════════════════════════════════════════════════════════════════
// Bootstrap
// ═══════════════════════════════════════════════════════════════════════════

$config  = require __DIR__ . '/config.php';
define('TABLE_PREFIX', $config['db']['table_prefix'] ?? '');
$dryRun     = in_array('--dry-run',       $argv ?? [], true);
$skipProd   = in_array('--skip-products', $argv ?? [], true);
$feedFormat = null;
foreach ($argv ?? [] as $arg) {
    if (preg_match('/^--feed-format=(csv|json|xml)$/', $arg, $m)) {
        $feedFormat = $m[1];
        break;
    }
}
$productsFromFeed = $feedFormat !== null;

// Media filter: --media=ID,ID on the CLI overrides config import.media_ids. Empty = all media.
$mediaFilter = array_map('intval', $config['import']['media_ids'] ?? []);
foreach ($argv ?? [] as $arg) {
    if (preg_match('/^--media=([\d,\s]+)$/', $arg, $m)) {
        $mediaFilter = array_map('intval', array_filter(array_map('trim', explode(',', $m[1])), 'strlen'));
        break;
    }
}
$mediaFilter = array_values(array_unique($mediaFilter));

$truncateArg = null; // null = off, [] = all tables, ['t1','t2'] = specific tables
foreach ($argv ?? [] as $arg) {
    if ($arg === '--truncate') {
        $truncateArg = [];
        break;
    }
    if (preg_match('/^--truncate=(.+)$/', $arg, $m)) {
        $truncateArg = array_map('trim', explode(',', $m[1]));
        break;
    }
}

const ALL_TABLES = [
    'countries', 'languages', 'locales',
    'airlines', 'airports', 'currencies',
    'public_holidays', 'regions',
    'annual_events', 'categories',
    'device_browsers', 'device_models', 'device_platforms', 'device_types',
    'publishers',
    'media', 'media_subscriptions', 'programs',
    'productfeeds', 'productfeed_standards', 'products', 'news',
];

if ($dryRun) {
    echo "[DRY RUN — no database writes]\n\n";
}

// ═══════════════════════════════════════════════════════════════════════════
// TokenManager — load / refresh / save OAuth tokens
// ═══════════════════════════════════════════════════════════════════════════

class TokenManager
{
    private array  $tokens = [];
    private string $tokensFile;
    private string $tokenUrl;
    private string $clientId;
    private string $clientSecret;

    public function __construct(array $oauthConfig)
    {
        $this->tokensFile   = $oauthConfig['tokens_file'];
        $this->tokenUrl     = $oauthConfig['token_url'];
        $this->clientId     = $oauthConfig['client_id'];
        $this->clientSecret = $oauthConfig['client_secret'] ?? '';
        $this->load();
    }

    public function getAccessToken(): string
    {
        // Refresh proactively 5 minutes before expiry
        if (($this->tokens['expires_at'] ?? 0) < time() + 300) {
            $this->refresh();
        }
        return $this->tokens['access_token'];
    }

    private function load(): void
    {
        if (!file_exists($this->tokensFile)) {
            echo "ERROR: {$this->tokensFile} not found.\n";
            echo "Run php auth_setup.php first to authenticate.\n";
            exit(1);
        }
        $data = json_decode(file_get_contents($this->tokensFile), true);
        if (empty($data['access_token'])) {
            echo "ERROR: tokens.json is invalid or missing access_token.\n";
            echo "Run php auth_setup.php to re-authenticate.\n";
            exit(1);
        }
        $this->tokens = $data;
    }

    private function refresh(): void
    {
        if (empty($this->tokens['refresh_token'])) {
            echo "ERROR: No refresh_token available. Run php auth_setup.php.\n";
            exit(1);
        }

        $postFields = [
            'grant_type'    => 'refresh_token',
            'refresh_token' => $this->tokens['refresh_token'],
            'client_id'     => $this->clientId,
        ];
        if ($this->clientSecret !== '') {
            $postFields['client_secret'] = $this->clientSecret;
        }

        $ch = curl_init($this->tokenUrl);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query($postFields),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/x-www-form-urlencoded',
                'Accept: application/json',
            ],
            CURLOPT_TIMEOUT => 30,
        ]);
        $body     = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200) {
            echo "ERROR: Token refresh failed (HTTP $httpCode):\n$body\n";
            echo "Run php auth_setup.php to re-authenticate.\n";
            exit(1);
        }

        $new = json_decode((string) $body, true);
        if (empty($new['access_token'])) {
            echo "ERROR: Token refresh returned unexpected response:\n$body\n";
            exit(1);
        }

        // Preserve refresh_token if the new response omits it
        if (empty($new['refresh_token']) && !empty($this->tokens['refresh_token'])) {
            $new['refresh_token'] = $this->tokens['refresh_token'];
        }
        $new['expires_at'] = time() + (int) ($new['expires_in'] ?? 3600);
        $this->tokens = $new;
        file_put_contents($this->tokensFile, json_encode($this->tokens, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        log_msg('Token refreshed, expires ' . date('H:i:s', $this->tokens['expires_at']));
    }
}

// ═══════════════════════════════════════════════════════════════════════════
// ApiClient — rate-limited HTTP calls + full pagination
// ═══════════════════════════════════════════════════════════════════════════

class ApiClient
{
    private string       $baseUrl;
    private int          $rateLimitPerMin;
    private int          $perPage;
    private TokenManager $tokens;
    private array        $requestLog = []; // unix timestamps of recent requests

    public function __construct(array $apiConfig, TokenManager $tokens)
    {
        $this->baseUrl         = rtrim($apiConfig['base_url'], '/');
        $this->rateLimitPerMin = $apiConfig['rate_limit_per_min'];
        $this->perPage         = $apiConfig['per_page'];
        $this->tokens          = $tokens;
    }

    /**
     * Fetch every page of a paginated endpoint and return all items.
     * Handles both plain-array responses and {results:[...]} responses.
     */
    public function fetchAll(string $path, array $params = [], string $envelopeKey = ''): array
    {
        $params['per_page'] = $this->perPage;
        $params['page']     = 1;
        $all = [];

        do {
            [$body, $headers] = $this->request('GET', $path, $params);
            $items   = $this->extractItems($body, $envelopeKey);
            $all     = array_merge($all, $items);
            $total   = isset($headers['x-total-count'])
                ? (int) $headers['x-total-count']
                : count($items);
            $fetched = count($all);
            $params['page']++;
        } while ($fetched < $total && count($items) > 0);

        return $all;
    }

    /**
     * Single GET request, returns [decoded_body, headers].
     */
    public function request(string $method, string $path, array $params = [], int $retries = 3): array
    {
        $this->throttle();

        $url = $this->baseUrl . $path;
        if ($method === 'GET' && !empty($params)) {
            $url .= '?' . $this->buildQuery($params);
        }

        $attempt = 0;
        while (true) {
            $attempt++;
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => 60,
                CURLOPT_HTTPHEADER     => [
                    'Authorization: Bearer ' . $this->tokens->getAccessToken(),
                    'Accept: application/json',
                ],
                CURLOPT_HEADER         => true,
            ]);

            if ($method === 'POST') {
                curl_setopt($ch, CURLOPT_POST, true);
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($params));
                curl_setopt($ch, CURLOPT_HTTPHEADER, [
                    'Authorization: Bearer ' . $this->tokens->getAccessToken(),
                    'Accept: application/json',
                    'Content-Type: application/json',
                ]);
            }

            $raw      = (string) curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $hdrSize  = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
            $curlErr  = curl_error($ch);
            curl_close($ch);

            if ($curlErr) {
                if ($attempt >= $retries) {
                    throw new RuntimeException("cURL error on $url: $curlErr");
                }
                sleep(2);
                continue;
            }

            $headers = $this->parseHeaders(substr($raw, 0, $hdrSize));
            $body    = json_decode(substr($raw, $hdrSize), true) ?? [];

            if ($httpCode === 429) {
                log_msg("Rate limited (429). Sleeping 60 s ...");
                sleep(60);
                $this->requestLog = []; // reset window
                continue;
            }

            if ($httpCode >= 500 && $attempt < $retries) {
                log_msg("Server error $httpCode on $path — retry $attempt/$retries");
                sleep(3 * $attempt);
                continue;
            }

            if ($httpCode === 401) {
                // Token might have just expired mid-flight; getAccessToken() refreshes it.
                if ($attempt === 1) {
                    continue;
                }
                throw new RuntimeException("Unauthorized (401) on $path — check tokens.");
            }

            if ($httpCode >= 400) {
                $msg = is_array($body) ? json_encode($body) : substr($raw, $hdrSize, 200);
                throw new RuntimeException("HTTP $httpCode on $path: $msg");
            }

            return [$body, $headers];
        }
    }

    // ── Private helpers ───────────────────────────────────────────────────

    private function extractItems(mixed $body, string $envelopeKey = ''): array
    {
        if (!is_array($body)) return [];
        // Nested envelope: {envelopeKey: {results: [...]}}
        if ($envelopeKey !== '' && isset($body[$envelopeKey]) && is_array($body[$envelopeKey])) {
            $body = $body[$envelopeKey];
        }
        // {results: [...]} envelope used by material/product-feeds endpoints
        if (isset($body['results']) && is_array($body['results'])) {
            return $body['results'];
        }
        // Plain array
        if (array_is_list($body)) {
            return $body;
        }
        return [];
    }

    /** Build query string; arrays become repeated params: key[]=v1&key[]=v2 */
    private function buildQuery(array $params): string
    {
        $parts = [];
        foreach ($params as $key => $value) {
            if (is_array($value)) {
                foreach ($value as $v) {
                    $parts[] = urlencode($key . '[]') . '=' . urlencode((string) $v);
                }
            } else {
                $parts[] = urlencode((string) $key) . '=' . urlencode((string) $value);
            }
        }
        return implode('&', $parts);
    }

    private function parseHeaders(string $raw): array
    {
        $headers = [];
        foreach (explode("\r\n", $raw) as $line) {
            if (str_contains($line, ':')) {
                [$k, $v] = explode(':', $line, 2);
                $headers[strtolower(trim($k))] = trim($v);
            }
        }
        return $headers;
    }

    /** Sliding-window rate limiter: max $rateLimitPerMin requests per 60 s */
    private function throttle(): void
    {
        $now    = microtime(true);
        $window = 60.0;

        // Drop entries older than the window
        $this->requestLog = array_values(
            array_filter($this->requestLog, fn($t) => $now - $t < $window)
        );

        if (count($this->requestLog) >= $this->rateLimitPerMin) {
            $oldest   = $this->requestLog[0];
            $sleepSec = $window - ($now - $oldest) + 0.1;
            if ($sleepSec > 0) {
                log_msg(sprintf("Rate limit: sleeping %.1f s ...", $sleepSec));
                usleep((int) ($sleepSec * 1_000_000));
            }
            $this->requestLog = [];
        }

        $this->requestLog[] = microtime(true);
    }
}

// ═══════════════════════════════════════════════════════════════════════════
// Database helpers
// ═══════════════════════════════════════════════════════════════════════════

function db(array $dbConfig = [], bool $reconnect = false): PDO
{
    static $pdo    = null;
    static $config = [];

    if (!empty($dbConfig)) {
        $config = $dbConfig;
    }
    if ($reconnect) {
        $pdo = null;
    }
    if ($pdo === null) {
        $dsn = "mysql:host={$config['host']};dbname={$config['name']};charset={$config['charset']}";
        $pdo = new PDO($dsn, $config['user'], $config['pass'], [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    }
    return $pdo;
}

function truncateTables(PDO $pdo, array $tables, bool $dryRun): void
{
    log_section('Truncating tables');
    foreach ($tables as $table) {
        $table = TABLE_PREFIX . $table;
        if ($dryRun) {
            log_msg("  [DRY RUN] TRUNCATE $table");
        } else {
            $pdo->exec("TRUNCATE TABLE `$table`");
            log_msg("  Truncated: $table");
        }
    }
}

/**
 * Upsert a single row: INSERT ... ON DUPLICATE KEY UPDATE every non-PK column.
 */
function upsert(PDO $pdo, string $table, array $row, bool $dryRun = false): void
{
    if ($dryRun) return;

    $cols = array_keys($row);
    $placeholders = array_map(fn($c) => ":$c", $cols);
    $updates      = array_map(fn($c) => "`$c` = VALUES(`$c`)", $cols);

    $sql = sprintf(
        "INSERT INTO `%s` (%s) VALUES (%s) ON DUPLICATE KEY UPDATE %s",
        TABLE_PREFIX . $table,
        implode(', ', array_map(fn($c) => "`$c`", $cols)),
        implode(', ', $placeholders),
        implode(', ', $updates)
    );

    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($row);
    } catch (PDOException $e) {
        if (str_contains($e->getMessage(), '2006') || str_contains($e->getMessage(), 'server has gone away')) {
            $pdo  = db(reconnect: true);
            $stmt = $pdo->prepare($sql);
            $stmt->execute($row);
        } else {
            throw $e;
        }
    }
}

// ═══════════════════════════════════════════════════════════════════════════
// Logging
// ═══════════════════════════════════════════════════════════════════════════

function log_msg(string $msg): void
{
    echo '[' . date('H:i:s') . '] ' . $msg . "\n";
}

function log_section(string $title): void
{
    echo "\n" . str_repeat('─', 60) . "\n";
    echo "  $title\n";
    echo str_repeat('─', 60) . "\n";
}

// ═══════════════════════════════════════════════════════════════════════════
// Import functions
// ═══════════════════════════════════════════════════════════════════════════

function importCountries(ApiClient $api, PDO $pdo, bool $dryRun): void
{
    log_section('Countries');
    $items = $api->fetchAll('/countries');
    log_msg(count($items) . ' countries fetched');

    foreach ($items as $item) {
        upsert($pdo, 'countries', [
            'id'               => (int) $item['id'],
            'code'             => (string) ($item['code'] ?? ''),
            'phone_prefix'     => $item['phone_prefix'] ?? null,
            'sepa_country'     => isset($item['sepa_country'])  ? (int)(bool)$item['sepa_country']  : null,
            'iban_country'     => isset($item['iban_country'])  ? (int)(bool)$item['iban_country']  : null,
            'european_country' => isset($item['european_country']) ? (int)(bool)$item['european_country'] : null,
            'priority'         => isset($item['priority'])      ? (int)(bool)$item['priority']      : null,
            'raw_data'         => json_encode($item),
        ], $dryRun);
    }
    log_msg('Countries saved.');
}

function importLanguages(ApiClient $api, PDO $pdo, bool $dryRun): array
{
    log_section('Languages');
    $items = $api->fetchAll('/languages');
    log_msg(count($items) . ' languages fetched');

    $langMap = []; // id => code
    foreach ($items as $item) {
        upsert($pdo, 'languages', [
            'id'       => (int) $item['id'],
            'code'     => (string) ($item['code'] ?? ''),
            'raw_data' => json_encode($item),
        ], $dryRun);
        $langMap[(int) $item['id']] = (string) ($item['code'] ?? '');
    }
    log_msg('Languages saved.');
    return $langMap;
}

function importLocales(ApiClient $api, PDO $pdo, bool $dryRun): void
{
    log_section('Locales');
    $items = $api->fetchAll('/locales');
    log_msg(count($items) . ' locales fetched');

    foreach ($items as $item) {
        upsert($pdo, 'locales', [
            'id'           => (string) ($item['id'] ?? ''),
            'country_id'   => isset($item['country_id'])   ? (int) $item['country_id']   : null,
            'country_code' => $item['country_code'] ?? null,
            'language_id'  => isset($item['language_id'])  ? (int) $item['language_id']  : null,
            'code'         => $item['code'] ?? null,
            'raw_data'     => json_encode($item),
        ], $dryRun);
    }
    log_msg('Locales saved.');
}

function importAirlines(ApiClient $api, PDO $pdo, bool $dryRun): void
{
    log_section('Airlines');
    $items = $api->fetchAll('/airline');
    log_msg(count($items) . ' airlines fetched');

    foreach ($items as $item) {
        upsert($pdo, 'airlines', [
            'id'           => (int) $item['id'],
            'name'         => $item['name']         ?? null,
            'alias'        => $item['alias']        ?? null,
            'callsign'     => $item['callsign']     ?? null,
            'country'      => $item['country']      ?? null,
            'country_code' => $item['country_code'] ?? null,
            'iata'         => $item['iata']         ?? null,
            'icao'         => $item['icao']         ?? null,
            'logo'         => $item['logo']         ?? null,
            'raw_data'     => json_encode($item),
        ], $dryRun);
    }
    log_msg('Airlines saved.');
}

function importAirports(ApiClient $api, PDO $pdo, bool $dryRun): void
{
    log_section('Airports');
    $items = $api->fetchAll('/airport');
    log_msg(count($items) . ' airports fetched');

    foreach ($items as $item) {
        upsert($pdo, 'airports', [
            'id'            => (int) $item['id'],
            'name'          => $item['name']          ?? null,
            'city'          => $item['city']          ?? null,
            'country'       => $item['country']       ?? null,
            'country_code'  => $item['country_code']  ?? null,
            'iata'          => $item['iata']          ?? null,
            'icao'          => $item['icao']          ?? null,
            'latitude'      => isset($item['latitude'])  ? (float) $item['latitude']  : null,
            'longitude'     => isset($item['longitude']) ? (float) $item['longitude'] : null,
            'altitude'      => isset($item['altitude'])  ? (float) $item['altitude']  : null,
            'timezone'      => isset($item['timezone'])  ? (float) $item['timezone']  : null,
            'dst'           => $item['dst']           ?? null,
            'timezone_name' => $item['timezone_name'] ?? null,
            'raw_data'      => json_encode($item),
        ], $dryRun);
    }
    log_msg('Airports saved.');
}

function importCurrencies(ApiClient $api, PDO $pdo, bool $dryRun): void
{
    log_section('Currencies');
    $items = $api->fetchAll('/currency');
    log_msg(count($items) . ' currencies fetched');

    foreach ($items as $item) {
        upsert($pdo, 'currencies', [
            'code'                  => (string) ($item['code'] ?? ''),
            'exchange_rate_to_euro' => isset($item['exchange_rate_to_euro']) ? (float) $item['exchange_rate_to_euro'] : null,
            'date_modified'         => $item['date_modified'] ?? null,
            'raw_data'              => json_encode($item),
        ], $dryRun);
    }
    log_msg('Currencies saved.');
}

function importPublicHolidays(ApiClient $api, PDO $pdo, bool $dryRun): void
{
    log_section('Public holidays');
    $items = $api->fetchAll('/public-holidays');
    log_msg(count($items) . ' public holidays fetched');

    foreach ($items as $item) {
        upsert($pdo, 'public_holidays', [
            'id'           => (string) ($item['id'] ?? ''),
            'start_date'   => $item['start_date']   ?? null,
            'end_date'     => $item['end_date']     ?? null,
            'country_code' => $item['country_code'] ?? null,
            'local_name'   => $item['local_name']   ?? null,
            'name'         => $item['name']         ?? null,
            'raw_data'     => json_encode($item),
        ], $dryRun);
    }
    log_msg('Public holidays saved.');
}

function importProductfeedStandard(ApiClient $api, PDO $pdo, bool $dryRun): void
{
    log_section('Productfeed standards');
    $items = $api->fetchAll('/product-feeds/standards');
    log_msg(count($items) . ' productfeed standards fetched');

    foreach ($items as $item) {
        upsert($pdo, 'productfeed_standards', [
            'id'       => (int) ($item['id'] ?? 0),
            'name'     => $item['name'] ?? null,
            'raw_data' => json_encode($item),
        ], $dryRun);
    }
    log_msg('Productfeed standards saved.');
}

function importRegions(ApiClient $api, PDO $pdo, bool $dryRun): void
{
    log_section('Regions');
    $items = $api->fetchAll('/regions');
    log_msg(count($items) . ' regions fetched');

    foreach ($items as $item) {
        upsert($pdo, 'regions', [
            'code'         => (string) ($item['code'] ?? ''),
            'name'         => $item['name']         ?? null,
            'country_code' => $item['country_code'] ?? null,
            'raw_data'     => json_encode($item),
        ], $dryRun);
    }
    log_msg('Regions saved.');
}

function importAnnualEvents(ApiClient $api, PDO $pdo, bool $dryRun): void
{
    log_section('Annual events');
    $items = $api->fetchAll('/annual-events');
    log_msg(count($items) . ' annual events fetched');

    foreach ($items as $item) {
        upsert($pdo, 'annual_events', [
            'id'         => (int) $item['id'],
            'name'       => $item['name']       ?? null,
            'restricted' => isset($item['restricted']) ? (int)(bool)$item['restricted'] : null,
            'raw_data'   => json_encode($item),
        ], $dryRun);
    }
    log_msg('Annual events saved.');
}

function importCategories(ApiClient $api, PDO $pdo, bool $dryRun): void
{
    log_section('Categories');
    $items = $api->fetchAll('/categories');
    log_msg(count($items) . ' categories fetched');

    foreach ($items as $item) {
        upsert($pdo, 'categories', [
            'id'       => (int) $item['id'],
            'name'     => $item['name'] ?? null,
            'type'     => isset($item['type']) ? json_encode($item['type']) : null,
            'raw_data' => json_encode($item),
        ], $dryRun);
    }
    log_msg('Categories saved.');
}

function importDeviceBrowsers(ApiClient $api, PDO $pdo, bool $dryRun): void
{
    log_section('Device browsers');
    $items = $api->fetchAll('/device/browsers');
    log_msg(count($items) . ' device browsers fetched');

    foreach ($items as $item) {
        upsert($pdo, 'device_browsers', [
            'id'            => (int) $item['id'],
            'name'          => $item['name']  ?? null,
            'minor_version' => isset($item['minor_version']) ? (int)$item['minor_version'] : null,
            'major_version' => isset($item['major_version']) ? (int)$item['major_version'] : null,
            'brand'         => $item['brand'] ?? null,
            'raw_data'      => json_encode($item),
        ], $dryRun);
    }
    log_msg('Device browsers saved.');
}

function importDeviceModels(ApiClient $api, PDO $pdo, bool $dryRun): void
{
    log_section('Device models');
    $items = $api->fetchAll('/device/models');
    log_msg(count($items) . ' device models fetched');

    foreach ($items as $item) {
        upsert($pdo, 'device_models', [
            'id'       => (int) $item['id'],
            'name'     => $item['name'] ?? null,
            'raw_data' => json_encode($item),
        ], $dryRun);
    }
    log_msg('Device models saved.');
}

function importDevicePlatforms(ApiClient $api, PDO $pdo, bool $dryRun): void
{
    log_section('Device platforms');
    $items = $api->fetchAll('/device/platforms');
    log_msg(count($items) . ' device platforms fetched');

    foreach ($items as $item) {
        upsert($pdo, 'device_platforms', [
            'id'       => (int) $item['id'],
            'name'     => $item['name']    ?? null,
            'version'  => $item['version'] ?? null,
            'brand'    => $item['brand']   ?? null,
            'raw_data' => json_encode($item),
        ], $dryRun);
    }
    log_msg('Device platforms saved.');
}

function importDeviceTypes(ApiClient $api, PDO $pdo, bool $dryRun): void
{
    log_section('Device types');
    $items = $api->fetchAll('/device/types');
    log_msg(count($items) . ' device types fetched');

    foreach ($items as $item) {
        upsert($pdo, 'device_types', [
            'id'       => (int) $item['id'],
            'name'     => $item['name'] ?? null,
            'raw_data' => json_encode($item),
        ], $dryRun);
    }
    log_msg('Device types saved.');
}

function importPublishers(ApiClient $api, PDO $pdo, bool $dryRun): void
{
    log_section('Publishers');
    $items = $api->fetchAll('/publishers');
    log_msg(count($items) . ' publishers fetched');

    foreach ($items as $item) {
        upsert($pdo, 'publishers', [
            'id'       => (int) $item['id'],
            'name'     => $item['name'] ?? null,
            'raw_data' => json_encode($item),
        ], $dryRun);
    }
    log_msg('Publishers saved.');
}

function importNews(ApiClient $api, PDO $pdo, int $publisherId, array $approvedProgramIds, bool $dryRun): void
{
    log_section("News (publisher $publisherId)");
    $since       = date('Y-m-d H:i:s', strtotime('-365 days'));
    $items       = $api->fetchAll("/publishers/{$publisherId}/news.v2", ['start' => $since]);
    $approvedSet = array_flip($approvedProgramIds);
    log_msg(count($items) . ' news items fetched');

    $saved = $skipped = 0;
    foreach ($items as $item) {
        $programId = isset($item['program_id']) ? (int) $item['program_id'] : null;
        if ($programId === null || !isset($approvedSet[$programId])) {
            $skipped++;
            continue;
        }

        upsert($pdo, 'news', [
            'id'           => (int) $item['id'],
            'publisher_id' => $publisherId,
            'source'       => $item['source']     ?? null,
            'program_id'   => $programId,
            'date'         => $item['date']        ?? null,
            'language_id'  => isset($item['language_id']) ? (int) $item['language_id'] : null,
            'locale_ids'   => isset($item['locale_ids'])  ? json_encode($item['locale_ids']) : null,
            'type'         => $item['type']        ?? null,
            'title'        => $item['title']       ?? null,
            'text'         => $item['text']        ?? null,
            'raw_data'     => json_encode($item),
        ], $dryRun);
        $saved++;
    }
    log_msg("News saved: $saved" . ($skipped > 0 ? " ($skipped skipped — program not in approved set)" : ''));
}

/**
 * Returns all publisher IDs for the authenticated account.
 */
function getPublisherIds(ApiClient $api): array
{
    $publishers = $api->fetchAll('/publishers');
    return array_map(fn($p) => (int) $p['id'], $publishers);
}

function importMedia(ApiClient $api, PDO $pdo, int $publisherId, array $langMap, bool $dryRun, array $mediaFilter = []): array
{
    log_section("Media (publisher $publisherId)");
    $items = $api->fetchAll("/publishers/{$publisherId}/media");
    log_msg(count($items) . ' media fetched');

    if (!empty($mediaFilter)) {
        $wanted = array_flip($mediaFilter);
        $items  = array_values(array_filter($items, fn($i) => isset($wanted[(int) ($i['id'] ?? 0)])));
        log_msg('Media filter: ' . implode(', ', $mediaFilter) . ' → ' . count($items) . ' matched for this publisher');
    }

    $activeIds     = [];
    $langCodeIndex = []; // collect unique language codes from active media
    $skipped = 0;
    foreach ($items as $item) {
        $status             = $item['status']              ?? '';
        $approvalStatus      = $item['approval_status']     ?? '';
        $verificationStatus  = $item['verification_status'] ?? '';

        if ($status !== 'active' || $approvalStatus !== 'approved' || $verificationStatus !== 'verified') {
            if (!empty($mediaFilter)) {
                log_msg("  Media {$item['id']} skipped — status=$status, approval=$approvalStatus, verification=$verificationStatus");
            }
            $skipped++;
            continue;
        }

        upsert($pdo, 'media', [
            'id'                  => (int) $item['id'],
            'publisher_id'        => $publisherId,
            'name'                => (string) ($item['name'] ?? ''),
            'location'            => $item['location']            ?? null,
            'description'         => $item['description']         ?? null,
            'type_id'             => isset($item['type_id'])           ? (int)$item['type_id']           : null,
            'mobile'              => isset($item['mobile'])            ? (int)(bool)$item['mobile']       : null,
            'status'              => $status,
            'approval_status'     => $approvalStatus,
            'verification_status' => $verificationStatus,
            'country_code'        => $item['country_code']        ?? null,
            'language_id'         => isset($item['language_id'])       ? (int)$item['language_id']       : null,
            'raw_data'            => json_encode($item),
        ], $dryRun);

        $activeIds[] = (int) $item['id'];
        $langId = isset($item['language_id']) ? (int) $item['language_id'] : null;
        if ($langId !== null && isset($langMap[$langId])) {
            $langCodeIndex[$langMap[$langId]] = true;
        }
    }
    $allowedLangCodes = array_keys($langCodeIndex);
    log_msg(count($activeIds) . ' media imported' . ($skipped > 0 ? " ($skipped skipped — not active/approved/verified)" : '') . ' | language codes: ' . (empty($allowedLangCodes) ? '(none)' : implode(', ', $allowedLangCodes)));
    return [$activeIds, $allowedLangCodes];
}

/**
 * Returns [media_id => [program_id, ...], ...] for approved subscriptions.
 */
function fetchApprovedSubscriptions(ApiClient $api, PDO $pdo, int $publisherId, array $mediaIds, bool $dryRun): array
{
    log_section("Approved subscriptions (publisher $publisherId)");
    $mapping = []; // media_id => [program_ids]

    foreach ($mediaIds as $mediaId) {
        $subs = $api->fetchAll("/publishers/{$publisherId}/media/{$mediaId}/subscriptions", [
            'status' => 'approved',
        ]);

        foreach ($subs as $sub) {
            $programIds = (array) ($sub['program_ids'] ?? []);
            foreach ($programIds as $programId) {
                $pid = (int) $programId;
                $mapping[$mediaId][] = $pid;

                upsert($pdo, 'media_subscriptions', [
                    'media_id'       => $mediaId,
                    'program_id'     => $pid,
                    'status'         => $sub['status']         ?? 'approved',
                    'cpc_status'     => $sub['cpc_status']     ?? null,
                    'subscribe_date' => $sub['subscribe_date'] ?? null,
                    'approval_date'  => $sub['approval_date']  ?? null,
                ], $dryRun);
            }
        }
        $count = count($mapping[$mediaId] ?? []);
        log_msg("  Media $mediaId: $count approved program(s)");
    }

    return $mapping;
}

/**
 * Returns program_ids for all active approved programs.
 */
function importPrograms(ApiClient $api, PDO $pdo, int $publisherId, array $mediaMapping, array $excludeProgramIds, array $excludeAdvertiserIds, bool $dryRun): array
{
    log_section("Programs (publisher $publisherId)");

    // Collect unique program IDs
    $uniqueIds = [];
    foreach ($mediaMapping as $programIds) {
        foreach ($programIds as $pid) {
            $uniqueIds[$pid] = true;
        }
    }
    $uniqueIds = array_keys($uniqueIds);
    log_msg(count($uniqueIds) . ' unique programs to import');

    if (empty($uniqueIds)) {
        return [[], []];
    }

    // Fetch program details one by one (API parameter accepts a single integer)
    $imported = [];
    foreach ($uniqueIds as $i => $programId) {
        $results = $api->fetchAll("/publishers/{$publisherId}/programs", [
            'program_id' => $programId,
            'per_page'   => 1,
        ]);

        $program = $results[0] ?? null;
        if ($program === null) {
            log_msg("  WARNING: Program $programId returned no data — skipping");
            continue;
        }

        if (($program['status'] ?? '') !== 'active') {
            continue;
        }

        if (isset($excludeProgramIds[$programId])) {
            continue;
        }

        $advId = isset($program['advertiser_id']) ? (int) $program['advertiser_id'] : null;

        if ($advId !== null && isset($excludeAdvertiserIds[$advId])) {
            continue;
        }

        upsert($pdo, 'programs', [
            'id'           => (int) $program['id'],
            'publisher_id' => $publisherId,
            'advertiser_id' => $advId,
            'name'         => $program['name']         ?? null,
            'url'          => $program['url']          ?? null,
            'display_url'  => $program['display_url']  ?? null,
            'status'       => $program['status']       ?? null,
            'currency_code' => $program['currency_code'] ?? null,
            'startdate'    => $program['startdate']    ?? null,
            'enddate'      => $program['enddate']      ?? null,
            'productfeed'  => $program['productfeed']  ?? null,
            'raw_data'     => json_encode($program),
        ], $dryRun);

        $imported[] = (int) $program['id'];

        $n = $i + 1;
        $total = count($uniqueIds);
        if ($n % 10 === 0 || $n === $total) {
            log_msg("  Programs: $n / $total");
        }
    }

    return $imported;
}

/**
 * Returns [program_id => [feed, ...]] and upserts each feed.
 * When $allowedLocaleIds is non-empty, only feeds whose locale_id is in that
 * set are included.  Falls back to $allowedLanguageCodes filtering otherwise.
 */
function importFeeds(ApiClient $api, PDO $pdo, int $publisherId, array $programIds, array $allowedLanguageCodes, bool $dryRun, array $allowedLocaleIds = []): array
{
    log_section("Product feeds — productfeed_v2 (publisher $publisherId)");

    $feedMap = []; // program_id => [feed_row, ...]
    $total   = count($programIds);
    $skipped = 0;

    foreach ($programIds as $i => $programId) {
        $feeds = $api->fetchAll("/publishers/{$publisherId}/productfeeds.v2/program", [
            'program_id' => $programId,
        ]);

        foreach ($feeds as $feed) {
            if (!empty($allowedLocaleIds)) {
                $feedLocaleIds = array_map('strval', (array) ($feed['locale_ids'] ?? []));
                if (empty(array_intersect($feedLocaleIds, $allowedLocaleIds))) {
                    $skipped++;
                    continue;
                }
            } elseif (!empty($allowedLanguageCodes)) {
                $langCode = $feed['language_code'] ?? '';
                if (!in_array($langCode, $allowedLanguageCodes, true)) {
                    $skipped++;
                    continue;
                }
            }

            $feedId = (int) $feed['id'];
            upsert($pdo, 'productfeeds', [
                'id'             => $feedId,
                'program_id'     => $programId,
                'url'            => $feed['url']           ?? null,
                'standard_id'    => isset($feed['standard_id'])  ? (int)$feed['standard_id']  : null,
                'currency_id'    => isset($feed['currency_id'])  ? (int)$feed['currency_id']  : null,
                'currency_code'  => $feed['currency_code']  ?? null,
                'language_code'  => $feed['language_code']  ?? null,
                'locale_ids'     => isset($feed['locale_ids'])   ? json_encode($feed['locale_ids']) : null,
                'products_count' => isset($feed['products'])     ? (int)$feed['products']      : 0,
                'last_run_date'  => $feed['last_run_date']  ?? null,
                'raw_data'       => json_encode($feed),
            ], $dryRun);

            $feed['_db_id'] = $feedId;
            $feedMap[$programId][] = $feed;
        }

        $n = $i + 1;
        if ($n % 20 === 0 || $n === $total) {
            $feedCount = array_sum(array_map('count', $feedMap));
            log_msg("  Programs: $n / $total | Feeds so far: $feedCount");
        }
    }

    $feedCount = array_sum(array_map('count', $feedMap));
    log_msg("Total feeds: $feedCount" . ($skipped > 0 ? " ($skipped skipped — language not in any media)" : ''));
    return $feedMap;
}

/**
 * Imports all products for every feed.
 * De-duplicates by (program_id, language_code) to avoid redundant API calls.
 */
function importProducts(ApiClient $api, PDO $pdo, int $publisherId, array $feedMap, bool $dryRun): void
{
    log_section("Products (publisher $publisherId)");

    // Build unique (program_id, language_code) → first feed_id mapping
    $jobs    = []; // key => ['program_id', 'language_code', 'feed_id']
    foreach ($feedMap as $programId => $feeds) {
        foreach ($feeds as $feed) {
            $lang = $feed['language_code'] ?? '';
            if ($lang === '') continue;
            $key = "{$programId}::{$lang}";
            if (!isset($jobs[$key])) {
                $jobs[$key] = [
                    'program_id'    => $programId,
                    'language_code' => $lang,
                    'feed_id'       => (int) $feed['_db_id'],
                ];
            }
        }
    }

    $totalJobs = count($jobs);
    log_msg("Unique (program × language) jobs: $totalJobs");

    $jobNum = 0;
    foreach ($jobs as $job) {
        $jobNum++;
        $programId = $job['program_id'];
        $langCode  = $job['language_code'];
        $feedId    = $job['feed_id'];

        log_msg("  Job $jobNum/$totalJobs — program $programId, lang $langCode");

        $page       = 1;
        $perPage    = 1000;
        $savedCount = 0;
        $total      = 0;

        do {
            try {
                [$body, $headers] = $api->request('GET', "/publishers/{$publisherId}/material/product-feeds/products", [
                    'program_id'    => [$programId],
                    'language_code' => $langCode,
                    'per_page'      => $perPage,
                    'page'          => $page,
                ]);
            } catch (RuntimeException $e) {
                // The products endpoint (Elasticsearch-backed) rejects requests whose
                // offset exceeds its internal limit (~10 000). Treat that as end-of-results.
                if (str_contains($e->getMessage(), '422')) {
                    log_msg(sprintf(
                        "  NOTE: Offset limit reached at page %d — saved %d of ~%d products for program %d (%s)",
                        $page, $savedCount, $total, $programId, $langCode
                    ));
                    break;
                }
                throw $e;
            }

            $items = is_array($body) && isset($body['results']) ? $body['results'] : [];
            // Capture total from the first page only; it can drift on later pages.
            if ($page === 1) {
                $total = isset($headers['x-total-count']) ? (int) $headers['x-total-count'] : count($items);
            }

            foreach ($items as $product) {
                upsert($pdo, 'products', [
                    'id'           => (string) ($product['id'] ?? ''),
                    'feed_id'      => $feedId,
                    'program_id'   => $programId,
                    'title'        => isset($product['title'])       ? iconv_substr((string)$product['title'], 0, 1000) : null,
                    'description'  => $product['description']        ?? null,
                    'offer_count'  => isset($product['offer_count']) ? (int)$product['offer_count'] : 0,
                    'lowest_price' => isset($product['lowest_price']) ? (float)$product['lowest_price'] : null,
                    'in_stock'     => isset($product['in_stock'])    ? (int)(bool)$product['in_stock'] : null,
                    'currency_code' => $product['currency_code']     ?? null,
                    'language_code' => $product['language_code']     ?? null,
                    'image'        => isset($product['image'])       ? iconv_substr((string)$product['image'], 0, 2000) : null,
                    'attributes'   => isset($product['attributes'])  ? json_encode($product['attributes'])  : null,
                    'program_ids'  => isset($product['program_ids']) ? json_encode($product['program_ids']) : null,
                    'standard_ids' => isset($product['standard_ids']) ? json_encode($product['standard_ids']) : null,
                    'raw_data'     => json_encode($product),
                ], $dryRun);
                $savedCount++;
            }

            log_msg(sprintf(
                "    Page %d — %d items | total %d | saved so far %d",
                $page,
                count($items),
                $total,
                $savedCount
            ));

            $page++;
        } while ($savedCount < $total && count($items) > 0);

        log_msg("  ✓ Program $programId ($langCode): $savedCount products");
    }
}

/**
 * Downloads a feed URL directly into a tmpfile and returns the open handle,
 * or null on failure. Retries up to $maxRetries times on transient errors.
 * Caller must fclose() the handle when done.
 * @return resource|null
 */
function downloadFeedToTempFile(string $url, int $maxRetries = 3): mixed
{
    if (str_starts_with($url, '//')) {
        $url = 'https:' . $url;
    }

    if (!str_starts_with($url, 'http://') && !str_starts_with($url, 'https://')) {
        log_msg("  WARNING: Skipping feed — not an absolute URL: $url");
        return null;
    }

    log_msg("  Downloading: $url");

    for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
        $tmp = tmpfile();
        $ch  = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_FILE            => $tmp,
            CURLOPT_FOLLOWLOCATION  => true,
            CURLOPT_ENCODING        => '',
            CURLOPT_HTTP_VERSION    => CURL_HTTP_VERSION_1_1,
            CURLOPT_LOW_SPEED_LIMIT => 10240,
            CURLOPT_LOW_SPEED_TIME  => 60,
        ]);
        curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr  = curl_error($ch);
        curl_close($ch);

        if (!$curlErr && $httpCode < 400) {
            rewind($tmp);
            $stat = fstat($tmp);
            log_msg(sprintf("  Downloaded: %s bytes", number_format($stat['size'] ?? 0)));
            return $tmp;
        }

        fclose($tmp);

        // 4xx = client error, retrying won't help
        if ($httpCode >= 400 && $httpCode < 500) {
            log_msg("  HTTP $httpCode downloading feed file — skipping");
            return null;
        }

        $isLastAttempt = $attempt === $maxRetries;
        if ($curlErr) {
            log_msg("  cURL error (attempt $attempt/$maxRetries): $curlErr" . ($isLastAttempt ? '' : ' — retrying…'));
        } else {
            log_msg("  HTTP $httpCode (attempt $attempt/$maxRetries)" . ($isLastAttempt ? '' : ' — retrying…'));
        }

        if (!$isLastAttempt) {
            sleep(10 * $attempt);
        }
    }

    return null;
}

/**
 * Yields associative rows from a CSV/TSV file handle one at a time.
 * Auto-detects delimiter (tab, semicolon, comma) from the header line.
 * Using a Generator means only one row is ever held in memory.
 * @param resource $fh
 */
function parseFeedCsvFile(mixed $fh): Generator
{
    $firstLine = fgets($fh);
    if ($firstLine === false) return;
    $firstLine = rtrim($firstLine, "\r\n");
    // Strip UTF-8 BOM if present
    if (str_starts_with($firstLine, "\xEF\xBB\xBF")) {
        $firstLine = substr($firstLine, 3);
    }

    $tabs   = substr_count($firstLine, "\t");
    $semis  = substr_count($firstLine, ';');
    $commas = substr_count($firstLine, ',');
    $delim  = ($tabs >= $semis && $tabs >= $commas) ? "\t" : ($semis >= $commas ? ';' : ',');

    $headers = array_map(fn($h) => strtolower(trim($h)), str_getcsv($firstLine, $delim));
    $count   = count($headers);
    //log_msg("  Delimiter: " . ($delim === "\t" ? 'TAB' : $delim) . " | " . count($headers) . " columns: " . implode(', ', $headers));

    while (($fields = fgetcsv($fh, 0, $delim)) !== false) {
        if (count($fields) > $count) {
            $fields = array_slice($fields, 0, $count);
        } elseif (count($fields) < $count) {
            $fields = array_pad($fields, $count, '');
        }
        yield array_combine($headers, $fields);
    }
}

/**
 * Streams product rows from a JSON feed without loading the whole file into memory.
 * Scans raw bytes for known array-container patterns ("products":[, "items":[, etc.)
 * and handles root-array format. Yields one flattened product row at a time, so only
 * a single product object is ever held in memory — safe for multi-hundred-MB feeds.
 * @param resource $fh
 */
function parseFeedJsonFile(mixed $fh): Generator
{
    // Patterns that introduce a list of product objects in known JSON feed envelopes.
    // The scanner matches these as raw byte substrings; string-value false-positives
    // are unlikely for feed data and would simply produce rows skipped by the caller.
    $triggers = ['"products":[', '"items":[', '"results":[', '"data":[', '"feed":['];
    $maxTLen  = max(array_map('strlen', $triggers)); // 12 — length of "products":[
    $chunkSz  = 65536; // 64 KB read buffer

    // state: 0 = scanning for trigger, 1 = between objects in array, 2 = inside object
    $state   = 0;
    $depth   = 0;
    $objBuf  = '';
    $inStr   = false;
    $esc     = false;
    $rolling = ''; // sliding window of last $maxTLen raw bytes (state 0 only)
    $first   = true;

    while (($chunk = fread($fh, $chunkSz)) !== false && $chunk !== '') {
        if ($first) {
            $first   = false;
            $trimmed = ltrim($chunk);
            if ($trimmed !== '' && $trimmed[0] === '[') {
                $state = 1; // root-array format: [{...},{...}]
            }
        }

        $cLen = strlen($chunk);
        for ($i = 0; $i < $cLen; $i++) {
            $c = $chunk[$i];

            if ($state === 0) {
                // Slide the window and check if any trigger ends at current position.
                $rolling .= $c;
                if (strlen($rolling) > $maxTLen) {
                    $rolling = substr($rolling, 1);
                }
                foreach ($triggers as $t) {
                    if (str_ends_with($rolling, $t)) {
                        $state   = 1;
                        $rolling = '';
                        break;
                    }
                }
            } elseif ($state === 1) {
                // Between product objects: wait for '{' or end of array.
                if ($c === '{') {
                    $state  = 2;
                    $depth  = 1;
                    $objBuf = '{';
                    $inStr  = false;
                    $esc    = false;
                } elseif ($c === ']') {
                    $state   = 0; // array closed; resume scanning for the next trigger
                    $rolling = '';
                }
            } else {
                // state 2: collect one complete product object character by character.
                $objBuf .= $c;
                if ($esc) {
                    $esc = false;
                } elseif ($inStr) {
                    if ($c === '\\') $esc = true;
                    elseif ($c === '"') $inStr = false;
                } else {
                    if ($c === '"') {
                        $inStr = true;
                    } elseif ($c === '{' || $c === '[') {
                        $depth++;
                    } elseif ($c === '}' || $c === ']') {
                        if (--$depth === 0) {
                            $product = json_decode($objBuf, true);
                            if (is_array($product)) {
                                // Flatten one level of nested sub-objects so that keys
                                // like product_info.sku and update_info.daisycon_unique_id
                                // surface as plain 'sku' / 'daisycon_unique_id'.
                                $nested = [];
                                $flat   = [];
                                foreach ($product as $k => $v) {
                                    $k = strtolower((string) $k);
                                    if (is_array($v)) {
                                        foreach ($v as $subK => $subV) {
                                            $nested[strtolower((string) $subK)] = $subV;
                                        }
                                    } else {
                                        $flat[$k] = $v;
                                    }
                                }
                                yield array_merge($nested, $flat); // outer scalars win
                            }
                            $state  = 1;
                            $objBuf = '';
                        }
                    }
                }
            }
        }
    }
}

/**
 * Recursively converts a SimpleXMLElement to a flat associative array.
 * Nested elements with children are JSON-encoded into a single string value.
 */
function xmlElementToArray(SimpleXMLElement $el): array
{
    $row = [];
    foreach ($el->attributes() as $k => $v) {
        $row[(string) $k] = (string) $v;
    }
    foreach ($el->children() as $k => $child) {
        $sub = xmlElementToArray($child);
        if (empty($sub)) {
            // Leaf node — take text value directly
            $row[(string) $k] = (string) $child;
        } else {
            // Container node — bubble its children up into the parent row
            foreach ($sub as $subKey => $subVal) {
                $row[$subKey] = $subVal;
            }
        }
    }
    return $row;
}

/**
 * Streams product rows from an XML feed using XMLReader.
 * Finds every <product> element regardless of depth and yields it one at a time,
 * so only a single product node is ever held in memory.
 * @param resource $fh
 */
function parseFeedXmlFile(mixed $fh): Generator
{
    $meta = stream_get_meta_data($fh);
    $path = $meta['uri'] ?? '';

    if ($path === '') {
        log_msg('  WARNING: Could not determine temp file path for XML streaming');
        return;
    }

    libxml_use_internal_errors(true);
    $reader = new XMLReader();
    if (!$reader->open($path, null, LIBXML_PARSEHUGE)) {
        log_msg('  WARNING: XMLReader could not open feed file');
        return;
    }

    $dom = new DOMDocument();

    while ($reader->read()) {
        if ($reader->nodeType !== XMLReader::ELEMENT || $reader->localName !== 'product') {
            continue;
        }
        $node = $reader->expand($dom);
        if ($node === false) continue;

        $el  = simplexml_import_dom($node);
        $row = xmlElementToArray($el);
        yield array_combine(
            array_map('strtolower', array_keys($row)),
            array_values($row)
        );
    }

    $reader->close();
}

/**
 * Imports products by downloading each feed's file URL and parsing the CSV,
 * instead of using the paginated API endpoint.
 * De-duplicates by (program_id, language_code) just like importProducts().
 */
function importProductsFromFeedFiles(PDO $pdo, array $feedMap, array $mediaMapping, string $format, bool $dryRun): void
{
    log_section("Products from feed files ($format)");

    // Invert mediaMapping ([media_id => [program_id, ...]] → [program_id => media_id])
    $programToMedia = [];
    foreach ($mediaMapping as $mediaId => $programIds) {
        foreach ($programIds as $pid) {
            $programToMedia[$pid] ??= $mediaId;
        }
    }

    // Build unique (program_id, language_code) → first feed mapping
    $jobs = [];
    foreach ($feedMap as $programId => $feeds) {
        foreach ($feeds as $feed) {
            $lang = $feed['language_code'] ?? '';
            if ($lang === '' || empty($feed['url'])) continue;
            $key = "{$programId}::{$lang}";
            if (!isset($jobs[$key])) {
                $mediaId = $programToMedia[$programId] ?? '';
                $url     = str_replace('#MEDIA_ID#', (string) $mediaId, $feed['url'])
                         . '&html_transform=none&encoding=utf8&nl2br=true&type=' . $format;
                $jobs[$key] = [
                    'program_id'    => $programId,
                    'feed_id'       => (int) $feed['_db_id'],
                    'language_code' => $lang,
                    'currency_code' => $feed['currency_code'] ?? null,
                    'url'           => $url,
                ];
            }
        }
    }

    $totalJobs = count($jobs);
    log_msg("Unique (program × language) jobs: $totalJobs");

    $jobNum = 0;
    foreach ($jobs as $job) {
        $jobNum++;
        $programId    = $job['program_id'];
        $feedId       = $job['feed_id'];
        $langCode     = $job['language_code'];
        $currencyCode = $job['currency_code'];

        log_msg("  Job $jobNum/$totalJobs — program $programId, lang $langCode");

        $fh = downloadFeedToTempFile($job['url']);
        if ($fh === null) {
            log_msg("  WARNING: Could not download feed file — skipping");
            continue;
        }

        // The download may have taken long enough for MySQL to close the idle connection.
        $pdo = db(reconnect: true);

        // Detect actual format from first bytes; overrides --feed-format when they disagree.
        $peek   = fread($fh, 5);
        rewind($fh);
        $ltrim  = ltrim((string) $peek);
        $actual = str_starts_with($ltrim, '<')              ? 'xml'
                : (str_starts_with($ltrim, '{') || str_starts_with($ltrim, '[') ? 'json' : $format);
        if ($actual !== $format) {
            log_msg("  Auto-detected format: $actual (flag was --feed-format=$format)");
        }

        // All three parsers are Generators — one row in memory at a time.
        $rows = match ($actual) {
            'json' => parseFeedJsonFile($fh),   // streaming scanner — safe for 700 MB+ feeds
            'xml'  => parseFeedXmlFile($fh),    // XMLReader — one <product> node at a time
            default => parseFeedCsvFile($fh),   // line-by-line CSV generator
        };
        $saved           = 0;
        $skipped         = 0;
        $firstRow        = null;
        $firstSkippedRow = null;
        foreach ($rows as $row) {
            $firstRow ??= $row;
            $productId = (string) ($row['daisycon_unique_id'] ?? '');
            if ($productId === '' || $productId === '0') {
                $skipped++;
                $firstSkippedRow ??= $row;
                continue;
            }

            $title    = $row['title'] ?? $row['name'] ?? null;
            $price    = $row['saleprice'] ?? $row['sale_price'] ?? $row['price'] ?? null;
            $image    = $row['image'] ?? $row['image_default'] ?? $row['image_medium']
                     ?? $row['image_large'] ?? $row['image_small']
                     ?? $row['image_url'] ?? $row['imageurl'] ?? null;
            $inStock  = array_key_exists('in_stock', $row)    ? (int)(bool)$row['in_stock']
                      : (array_key_exists('instock', $row)    ? (int)(bool)$row['instock']
                      : (array_key_exists('availability', $row) ? (int)(strtolower($row['availability']) === 'in stock') : null));
            $currency = $row['currency_code'] ?? $row['currency'] ?? $currencyCode;
            $lang     = $row['language_code'] ?? $row['language'] ?? $langCode;

            // Known scalar fields; everything else goes into attributes.
            $knownKeys = ['id', 'sku', 'ean', 'daisycon_unique_id', 'title', 'name', 'description',
                          'price', 'saleprice', 'sale_price', 'in_stock', 'instock', 'availability',
                          'currency', 'currency_code', 'language', 'language_code',
                          'image', 'image_url', 'imageurl', 'image_small', 'image_medium',
                          'image_large', 'image_default'];
            $extra = array_diff_key($row, array_flip($knownKeys));

            upsert($pdo, 'products', [
                'id'            => $productId,
                'feed_id'       => $feedId,
                'program_id'    => $programId,
                'title'         => $title !== null ? iconv_substr((string) $title, 0, 1000) : null,
                'description'   => $row['description'] ?? null,
                'offer_count'   => 1,
                'lowest_price'  => $price !== null ? (float) $price : null,
                'in_stock'      => $inStock,
                'currency_code' => $currency,
                'language_code' => $lang,
                'image'         => $image !== null ? iconv_substr((string) $image, 0, 2000) : null,
                'attributes'    => !empty($extra) ? json_encode($extra) : null,
                'program_ids'   => json_encode([$programId]),
                'standard_ids'  => null,
                'raw_data'      => json_encode($row),
            ], $dryRun);
            $saved++;
        }
        fclose($fh);
        if ($skipped > 0) {
            log_msg("  WARNING: $skipped rows skipped — daisycon_unique_id missing or empty.");
            if ($firstSkippedRow !== null) {
                log_msg("  Available keys in first skipped row: " . implode(', ', array_keys($firstSkippedRow)));
            }
        }
        if ($saved === 0 && $skipped === 0 && $firstRow !== null) {
            log_msg("  WARNING: 0 saved. Available keys: " . implode(', ', array_keys($firstRow)));
        }
        log_msg("  ✓ Program $programId ($langCode): " . ($saved + $skipped) . " rows parsed, $skipped skipped, $saved saved");
    }
}

// ═══════════════════════════════════════════════════════════════════════════
// Main
// ═══════════════════════════════════════════════════════════════════════════

$start   = time();
$tokens  = new TokenManager($config['oauth']);
$api     = new ApiClient($config['api'], $tokens);
$pdo     = db($config['db']);

if ($truncateArg !== null) {
    truncateTables($pdo, $truncateArg === [] ? ALL_TABLES : $truncateArg, $dryRun);
}

$modeLabel = ($feedFormat !== null ? " [products from $feedFormat feed files]" : '')
    . (!empty($mediaFilter) ? ' [media: ' . implode(', ', $mediaFilter) . ']' : '')
    . ($dryRun ? ' [DRY RUN]' : '');
log_msg('Daisycon import started' . $modeLabel);

// ── 1. Common reference data ──────────────────────────────────────────────
importCountries($api, $pdo, $dryRun);
$langMap = importLanguages($api, $pdo, $dryRun);
importLocales($api, $pdo, $dryRun);
importAirlines($api, $pdo, $dryRun);
importAirports($api, $pdo, $dryRun);
importCurrencies($api, $pdo, $dryRun);
importProductfeedStandard($api, $pdo, $dryRun);
importPublicHolidays($api, $pdo, $dryRun);
importRegions($api, $pdo, $dryRun);
importAnnualEvents($api, $pdo, $dryRun);
importCategories($api, $pdo, $dryRun);
importDeviceBrowsers($api, $pdo, $dryRun);
importDeviceModels($api, $pdo, $dryRun);
importDevicePlatforms($api, $pdo, $dryRun);
importDeviceTypes($api, $pdo, $dryRun);

// ── 2. Publisher data ─────────────────────────────────────────────────────
importPublishers($api, $pdo, $dryRun);
$publisherIds = getPublisherIds($api);
log_msg('Publishers found: ' . implode(', ', $publisherIds));

$excludeProgramIds    = array_flip(array_map('intval', $config['import']['exclude_program_ids']    ?? []));
$excludeAdvertiserIds = array_flip(array_map('intval', $config['import']['exclude_advertiser_ids'] ?? []));

// Resolve configured locale codes → locale IDs once (locales table is already populated).
$allowedLocaleIds    = [];
$configuredLocaleCodes = $config['import']['locale_codes'] ?? [];
if (!empty($configuredLocaleCodes)) {
    // Accept both 'nl_NL' and 'nl-NL' spellings in the config.
    $normalised   = array_map(fn($c) => str_replace('-', '_', strtolower($c)), $configuredLocaleCodes);
    $placeholders = implode(',', array_fill(0, count($normalised), '?'));
    $stmt = $pdo->prepare(
        "SELECT id FROM `" . TABLE_PREFIX . "locales` WHERE LOWER(REPLACE(code, '-', '_')) IN ($placeholders)"
    );
    $stmt->execute($normalised);
    $allowedLocaleIds = array_column($stmt->fetchAll(), 'id');
    log_msg('Locale filter: ' . implode(', ', $configuredLocaleCodes)
        . ' → locale IDs: ' . (empty($allowedLocaleIds) ? '(none matched)' : implode(', ', $allowedLocaleIds)));
}

foreach ($publisherIds as $publisherId) {

    // ── 3. Media ──────────────────────────────────────────────────────────
    [$mediaIds, $allowedLangCodes] = importMedia($api, $pdo, $publisherId, $langMap, $dryRun, $mediaFilter);
    if (!empty($mediaFilter) && empty($mediaIds)) {
        log_msg("No selected media for publisher $publisherId — skipping its programs, feeds, products and news.");
        continue;
    }

    // ── 4. Approved subscriptions ─────────────────────────────────────────
    $mediaMapping = fetchApprovedSubscriptions($api, $pdo, $publisherId, $mediaIds, $dryRun);

    // ── 5. Programs ───────────────────────────────────────────────────────
    $programIds = importPrograms($api, $pdo, $publisherId, $mediaMapping, $excludeProgramIds, $excludeAdvertiserIds, $dryRun);

    // ── 6. Product feeds ──────────────────────────────────────────────────
    $feedMap = importFeeds($api, $pdo, $publisherId, $programIds, $allowedLangCodes, $dryRun, $allowedLocaleIds);

    // ── 7. Products ───────────────────────────────────────────────────────
    if (!$skipProd) {
        if ($productsFromFeed) {
            importProductsFromFeedFiles($pdo, $feedMap, $mediaMapping, $feedFormat, $dryRun);
        } else {
            importProducts($api, $pdo, $publisherId, $feedMap, $dryRun);
        }
    } else {
        log_msg('Products skipped (--skip-products)');
    }

    // ── 8. News ───────────────────────────────────────────────────────────
    importNews($api, $pdo, $publisherId, $programIds, $dryRun);
}

$elapsed = time() - $start;
log_msg(sprintf('Import complete in %02d:%02d', intdiv($elapsed, 60), $elapsed % 60));

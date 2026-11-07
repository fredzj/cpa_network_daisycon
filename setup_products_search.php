<?php
declare(strict_types=1);

/**
 * Products Search — Database Setup
 *
 * For each productfeed_standard, scans raw_data of matching products,
 * discovers extra JSON fields, and creates (or migrates) the target table.
 *
 * Re-running is safe: new columns are added via ALTER TABLE; columns whose
 * inferred type changed are modified in place (no data loss).
 *
 * Run once: php setup_products_search.php
 */

$sourceCfg = require __DIR__ . '/config.php';
$db        = $sourceCfg['db'];
$p         = $db['table_prefix'] ?? '';

// standard_id → target table name
const STANDARDS = [
    1  => 'products_general',
    7  => 'products_fashion',
    16 => 'products_accomodations',
    17 => 'products_transport',
    18 => 'products_accommodations_transport',
    21 => 'products_daily_offers',
];

// Columns already present in products — never duplicated from raw_data.
const PRODUCT_COLS = [
    'id', 'feed_id', 'program_id', 'title', 'description',
    'offer_count', 'lowest_price', 'in_stock', 'currency_code',
    'language_code', 'image', 'attributes', 'program_ids',
    'standard_ids', 'raw_data', 'imported_at',
];

// Per-table whitelist of extra (raw_data) columns to include.
// Tables not listed here get all discovered extra columns.
const TABLE_ALLOWED_EXTRA_COLS = [
    'products_accomodations' => [
        'accommodation_address', 'accommodation_bathrooms', 'accommodation_bedrooms',
        'accommodation_child_friendly', 'accommodation_floors', 'accommodation_lowest_date',
        'accommodation_lowest_price', 'accommodation_pets_allowed', 'accommodation_rooms',
        'accommodation_smoking_allowed', 'accommodation_type', 'additional_costs',
        'arrival_date', 'available', 'available_from', 'category', 'daisycon_unique_id',
        'departure_date', 'destination_city', 'destination_city_link', 'destination_country',
        'destination_country_link', 'destination_language', 'destination_location_description',
        'destination_region', 'destination_region_link', 'duration_days', 'duration_nights',
        'has_airco', 'has_barbecue', 'has_electricity', 'has_internet', 'has_sauna',
        'has_swimmingpool', 'has_washingmachine', 'images', 'in_stock_amount', 'keywords',
        'link', 'max_nr_people', 'price', 'star_rating', 'travel_trip_type',
    ],
    'products_transport' => [
        'airline', 'airline_code', 'airport_departure', 'airport_destination',
        'airportcode_departure', 'airportcode_destination', 'daisycon_unique_id',
        'departure_date', 'departure_date_return', 'destination_continent',
        'destination_country', 'duration_days', 'images', 'link', 'price',
        'travel_tour_operator', 'travel_transportation_type',
    ],
];

// Per-table subset of base product columns to create in the target table.
// Tables not listed here get all base columns.
const TABLE_BASE_COLS = [
    'products_accomodations' => ['id', 'feed_id', 'program_id', 'title', 'description', 'lowest_price'],
    'products_transport'     => ['id', 'feed_id', 'program_id', 'title', 'lowest_price'],
];

function connectDb(array $db): PDO
{
    $dsn = "mysql:host={$db['host']};dbname={$db['name']};charset={$db['charset']}";
    return new PDO($dsn, $db['user'], $db['pass'], [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
}

function log_msg(string $msg): void
{
    echo '[' . date('H:i:s') . '] ' . $msg . "\n";
}

function sanitizeColName(string $key): string
{
    $name = preg_replace('/[^a-z0-9_]/', '_', strtolower(trim($key)));
    $name = trim($name, '_');
    if ($name === '' || ctype_digit($name[0])) {
        $name = 'col_' . $name;
    }
    return $name ?: 'col_unknown';
}

/**
 * Infer MariaDB column type from observed values.
 * When a key appeared both as an array AND as a scalar across different rows,
 * we use TEXT rather than JSON to avoid the MariaDB JSON CHECK constraint.
 */
function inferColType(int $maxLen, bool $isInt, bool $isFloat, bool $hasArray, bool $hasScalar): string
{
    if ($hasArray && $hasScalar) return 'TEXT';        // mixed → plain text, no JSON check
    if ($hasArray)               return 'JSON';
    if ($isInt)                  return 'BIGINT';
    if ($isFloat)                return 'DECIMAL(15,4)';
    if ($maxLen <= 0)            return 'VARCHAR(255)';
    if ($maxLen <= 255)          return 'VARCHAR(255)';
    if ($maxLen <= 1000)         return 'VARCHAR(1000)';
    if ($maxLen <= 4000)         return 'TEXT';
    return 'MEDIUMTEXT';
}

/**
 * Returns true when the type MariaDB reports for an existing column is
 * compatible with the type we want to create/keep.
 */
function typesMatch(string $dbType, string $targetType): bool
{
    // Normalise: lowercase, strip integer display widths.
    $db  = preg_replace('/^(tinyint|smallint|mediumint|int|bigint)\(\d+\)/', '$1',
                         strtolower(trim($dbType)));
    $tgt = preg_replace('/^(tinyint|smallint|mediumint|int|bigint)\(\d+\)/', '$1',
                         strtolower(trim($targetType)));

    // MariaDB stores JSON as longtext (+ a CHECK constraint).
    if ($tgt === 'json' && ($db === 'longtext' || $db === 'json')) return true;

    return $db === $tgt;
}

function buildCreateTable(string $tableName, array $extraCols, ?array $allowedBaseCols = null): string
{
    $allBaseDefs = [
        'id'            => sprintf("    %-28s %-16s NOT NULL",     '`id`',            'VARCHAR(512)'),
        'feed_id'       => sprintf("    %-28s %-16s NOT NULL",     '`feed_id`',       'INT UNSIGNED'),
        'program_id'    => sprintf("    %-28s %-16s NOT NULL",     '`program_id`',    'INT UNSIGNED'),
        'title'         => sprintf("    %-28s %-16s DEFAULT NULL", '`title`',         'VARCHAR(1000)'),
        'description'   => sprintf("    %-28s %-16s DEFAULT NULL", '`description`',   'MEDIUMTEXT'),
        'offer_count'   => sprintf("    %-28s %-16s DEFAULT 0",    '`offer_count`',   'INT UNSIGNED'),
        'lowest_price'  => sprintf("    %-28s %-16s DEFAULT NULL", '`lowest_price`',  'DECIMAL(15,4)'),
        'in_stock'      => sprintf("    %-28s %-16s DEFAULT NULL", '`in_stock`',      'TINYINT(1)'),
        'currency_code' => sprintf("    %-28s %-16s DEFAULT NULL", '`currency_code`', 'VARCHAR(10)'),
        'language_code' => sprintf("    %-28s %-16s DEFAULT NULL", '`language_code`', 'VARCHAR(20)'),
        'image'         => sprintf("    %-28s %-16s DEFAULT NULL", '`image`',         'VARCHAR(2000)'),
        'attributes'    => sprintf("    %-28s %-16s DEFAULT NULL", '`attributes`',    'JSON'),
        'program_ids'   => sprintf("    %-28s %-16s DEFAULT NULL", '`program_ids`',   'JSON'),
        'standard_ids'  => sprintf("    %-28s %-16s DEFAULT NULL", '`standard_ids`',  'JSON'),
    ];

    if ($allowedBaseCols !== null) {
        $allowed = array_flip($allowedBaseCols);
        $lines   = array_values(array_filter(
            $allBaseDefs,
            fn($col) => isset($allowed[$col]),
            ARRAY_FILTER_USE_KEY
        ));
    } else {
        $lines = array_values($allBaseDefs);
    }

    foreach ($extraCols as $col => $info) {
        $lines[] = sprintf("    %-28s %-16s DEFAULT NULL", "`$col`", $info['type']);
    }

    $lines[] = sprintf("    %-28s %-16s NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP",
        '`imported_at`', 'TIMESTAMP');
    $lines[] = '    PRIMARY KEY (`id`)';
    $lines[] = '    KEY `idx_feed_id` (`feed_id`)';
    $lines[] = '    KEY `idx_program_id` (`program_id`)';
    if ($allowedBaseCols === null || in_array('language_code', $allowedBaseCols, true)) {
        $lines[] = '    KEY `idx_language_code` (`language_code`)';
    }
    if ($allowedBaseCols === null || in_array('in_stock', $allowedBaseCols, true)) {
        $lines[] = '    KEY `idx_in_stock` (`in_stock`)';
    }

    return "CREATE TABLE IF NOT EXISTS `$tableName` (\n"
         . implode(",\n", $lines) . "\n"
         . ") ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci";
}

// ── Connect ────────────────────────────────────────────────────────────────

log_msg('Connecting to DB (' . $db['name'] . ')...');
$pdo     = connectDb($db);
$skipSet = array_flip(PRODUCT_COLS);

$schema = ['tables' => []];

// ── Per-standard: discover extra keys, create/migrate table ───────────────

$tableExists = function (string $table) use ($pdo): bool {
    $stmt = $pdo->prepare('SHOW TABLES LIKE ?');
    $stmt->execute([addcslashes($table, '_%\\')]);
    return $stmt->fetchColumn() !== false;
};

foreach (STANDARDS as $standardId => $tableName) {
    // $tableName is the logical name (used as config key); $physTable is the real DB table.
    $physTable = $p . $tableName;
    echo "\n";
    log_msg("Standard $standardId → $physTable");

    // Existing installs: rename the unprefixed table to keep its data.
    if ($p !== '' && $tableExists($tableName) && !$tableExists($physTable)) {
        $pdo->exec("RENAME TABLE `$tableName` TO `$physTable`");
        log_msg("  ↻ Renamed '$tableName' → '$physTable'");
    }

    $keyStats = [];

    $stmt = $pdo->prepare("
        SELECT p.raw_data
          FROM `{$p}products` p
          JOIN `{$p}productfeeds` pf ON pf.id = p.feed_id
         WHERE pf.standard_id = :sid
           AND p.raw_data IS NOT NULL
    ");
    $stmt->execute([':sid' => $standardId]);

    $scanned = 0;
    while ($row = $stmt->fetch()) {
        $data = json_decode((string) $row['raw_data'], true);
        if (!is_array($data)) continue;

        foreach ($data as $origKey => $value) {
            $lk = strtolower(trim((string) $origKey));
            if (isset($skipSet[$lk])) continue;

            $col = sanitizeColName($lk);
            // Also skip if sanitized name collides with a product column (e.g. "offer count" → "offer_count").
            if (isset($skipSet[$col])) continue;
            if (!isset($keyStats[$col])) {
                $keyStats[$col] = [
                    'orig'       => $lk,
                    'max_len'    => 0,
                    'is_int'     => true,
                    'is_float'   => true,
                    'has_array'  => false,
                    'has_scalar' => false,
                ];
            }

            if (is_array($value)) {
                $keyStats[$col]['has_array'] = true;
                $keyStats[$col]['is_int']    = false;
                $keyStats[$col]['is_float']  = false;
            } elseif ($value !== null && $value !== '') {
                $keyStats[$col]['has_scalar'] = true;
                $str  = (string) $value;
                $len  = mb_strlen($str);
                if ($len > $keyStats[$col]['max_len']) {
                    $keyStats[$col]['max_len'] = $len;
                }
                $lstr = ltrim($str, '-');
                if ($keyStats[$col]['is_int'] && (!ctype_digit($lstr) || strlen($str) > 18 || $lstr === '')) {
                    $keyStats[$col]['is_int'] = false;
                }
                if ($keyStats[$col]['is_float'] && !is_numeric($str)) {
                    $keyStats[$col]['is_float'] = false;
                }
            }
        }
        $scanned++;
    }

    log_msg("  Scanned $scanned rows — " . count($keyStats) . " extra raw_data keys");

    $extraCols = [];
    foreach ($keyStats as $col => $stats) {
        $extraCols[$col] = [
            'orig_key' => $stats['orig'],
            'type'     => inferColType(
                $stats['max_len'],
                $stats['is_int'],
                $stats['is_float'],
                $stats['has_array'],
                $stats['has_scalar']
            ),
        ];
    }
    ksort($extraCols);

    // Filter extra columns by allowlist if defined for this table.
    $allowedExtra = TABLE_ALLOWED_EXTRA_COLS[$tableName] ?? null;
    if ($allowedExtra !== null) {
        $allowedExtraFlip = array_flip($allowedExtra);
        $extraCols = array_filter($extraCols, fn($col) => isset($allowedExtraFlip[$col]), ARRAY_FILTER_USE_KEY);
        ksort($extraCols);
    }

    if (!empty($extraCols)) {
        log_msg('  Extra cols: ' . implode(', ', array_keys($extraCols)));
    }

    // ── Create table if it doesn't exist ──────────────────────────────────

    $createSql = buildCreateTable($physTable, $extraCols, TABLE_BASE_COLS[$tableName] ?? null);
    $pdo->exec($createSql);

    // ── Migrate existing table: add new cols, fix changed types ───────────

    $existingTypes = [];
    $colStmt = $pdo->query("SHOW COLUMNS FROM `$physTable`");
    while ($col = $colStmt->fetch()) {
        $existingTypes[strtolower($col['Field'])] = strtolower($col['Type']);
    }

    foreach ($extraCols as $col => $info) {
        $targetType  = $info['type'];
        $existingType = $existingTypes[$col] ?? null;

        if ($existingType === null) {
            $pdo->exec("ALTER TABLE `$physTable` ADD COLUMN `$col` $targetType DEFAULT NULL");
            log_msg("  + Added column:    $col $targetType");
        } elseif (!typesMatch($existingType, $targetType)) {
            $pdo->exec("ALTER TABLE `$physTable` MODIFY COLUMN `$col` $targetType DEFAULT NULL");
            log_msg("  ~ Modified column: $col  ($existingType → $targetType)");
        }
    }

    log_msg("  Table '$physTable' ready.");

    $schema['tables'][$tableName] = [
        'standard_id' => $standardId,
        'extra_cols'  => array_values(array_map(
            fn($col, $info) => ['col' => $col, 'orig_key' => $info['orig_key'], 'type' => $info['type']],
            array_keys($extraCols),
            array_values($extraCols)
        )),
    ];
}

// ── Save schema ────────────────────────────────────────────────────────────

file_put_contents(
    __DIR__ . '/products_search_schema.json',
    json_encode($schema, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
);

echo "\n";
log_msg('Schema saved to products_search_schema.json');
log_msg('Setup complete. Run: php transform_products.php');

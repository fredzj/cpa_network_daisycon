<?php
declare(strict_types=1);

/**
 * Daisycon API Import — Database Setup
 * Run once: php setup.php
 */

$config = require __DIR__ . '/config.php';
$db = $config['db'];
$p  = $db['table_prefix'] ?? '';

try {
    $dsn = "mysql:host={$db['host']};dbname={$db['name']};charset={$db['charset']}";
    $pdo = new PDO($dsn, $db['user'], $db['pass'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
} catch (PDOException $e) {
    echo "DB connection failed: " . $e->getMessage() . "\n";
    exit(1);
}

$tables = [

    'countries' => "CREATE TABLE IF NOT EXISTS {$p}countries (
        id               INT UNSIGNED NOT NULL,
        code             VARCHAR(10)  NOT NULL,
        phone_prefix     VARCHAR(20)  DEFAULT NULL,
        sepa_country     TINYINT(1)   DEFAULT NULL,
        iban_country     TINYINT(1)   DEFAULT NULL,
        european_country TINYINT(1)   DEFAULT NULL,
        priority         TINYINT(1)   DEFAULT NULL,
        raw_data         JSON         DEFAULT NULL,
        imported_at      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci",

    'languages' => "CREATE TABLE IF NOT EXISTS {$p}languages (
        id          INT UNSIGNED NOT NULL,
        code        VARCHAR(20)  NOT NULL,
        raw_data    JSON         DEFAULT NULL,
        imported_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci",

    'locales' => "CREATE TABLE IF NOT EXISTS {$p}locales (
        id           VARCHAR(20) NOT NULL,
        country_id   INT UNSIGNED DEFAULT NULL,
        country_code VARCHAR(10)  DEFAULT NULL,
        language_id  INT UNSIGNED DEFAULT NULL,
        code         VARCHAR(20)  DEFAULT NULL,
        raw_data     JSON         DEFAULT NULL,
        imported_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci",

    'media' => "CREATE TABLE IF NOT EXISTS {$p}media (
        id                  INT UNSIGNED NOT NULL,
        publisher_id        INT UNSIGNED NOT NULL,
        name                VARCHAR(255) NOT NULL,
        location            VARCHAR(1000) DEFAULT NULL,
        description         TEXT         DEFAULT NULL,
        type_id             INT UNSIGNED DEFAULT NULL,
        mobile              TINYINT(1)   DEFAULT NULL,
        status              VARCHAR(50)  DEFAULT NULL,
        approval_status     VARCHAR(50)  DEFAULT NULL,
        verification_status VARCHAR(50)  DEFAULT NULL,
        country_code        VARCHAR(10)  DEFAULT NULL,
        language_id         INT UNSIGNED DEFAULT NULL,
        raw_data            JSON         DEFAULT NULL,
        imported_at         TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY idx_publisher_id (publisher_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci",

    'programs' => "CREATE TABLE IF NOT EXISTS {$p}programs (
        id           INT UNSIGNED NOT NULL,
        publisher_id INT UNSIGNED NOT NULL,
        advertiser_id INT UNSIGNED DEFAULT NULL,
        name         VARCHAR(255) DEFAULT NULL,
        url          VARCHAR(1000) DEFAULT NULL,
        display_url  VARCHAR(1000) DEFAULT NULL,
        status       VARCHAR(50)  DEFAULT NULL,
        currency_code VARCHAR(10) DEFAULT NULL,
        startdate    VARCHAR(50)  DEFAULT NULL,
        enddate      VARCHAR(50)  DEFAULT NULL,
        productfeed  VARCHAR(10)  DEFAULT NULL,
        raw_data     JSON         DEFAULT NULL,
        imported_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY idx_publisher_id (publisher_id),
        KEY idx_advertiser_id (advertiser_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci",

    'media_subscriptions' => "CREATE TABLE IF NOT EXISTS {$p}media_subscriptions (
        media_id       INT UNSIGNED NOT NULL,
        program_id     INT UNSIGNED NOT NULL,
        status         VARCHAR(50)  DEFAULT NULL,
        cpc_status     VARCHAR(50)  DEFAULT NULL,
        subscribe_date VARCHAR(50)  DEFAULT NULL,
        approval_date  VARCHAR(50)  DEFAULT NULL,
        imported_at    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (media_id, program_id),
        KEY idx_program_id (program_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci",

    'productfeeds' => "CREATE TABLE IF NOT EXISTS {$p}productfeeds (
        id             INT UNSIGNED  NOT NULL,
        program_id     INT UNSIGNED  NOT NULL,
        url            VARCHAR(2000) DEFAULT NULL,
        standard_id    INT UNSIGNED  DEFAULT NULL,
        currency_id    INT UNSIGNED  DEFAULT NULL,
        currency_code  VARCHAR(10)   DEFAULT NULL,
        language_code  VARCHAR(20)   DEFAULT NULL,
        locale_ids     JSON          DEFAULT NULL,
        products_count INT UNSIGNED  DEFAULT 0,
        last_run_date  VARCHAR(50)   DEFAULT NULL,
        raw_data       JSON          DEFAULT NULL,
        imported_at    TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY idx_program_id (program_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci",

    'products' => "CREATE TABLE IF NOT EXISTS {$p}products (
        id            VARCHAR(512)   NOT NULL,
        feed_id       INT UNSIGNED   NOT NULL,
        program_id    INT UNSIGNED   NOT NULL,
        title         VARCHAR(1000)  DEFAULT NULL,
        description   MEDIUMTEXT     DEFAULT NULL,
        offer_count   INT UNSIGNED   DEFAULT 0,
        lowest_price  DECIMAL(15, 4) DEFAULT NULL,
        in_stock      TINYINT(1)     DEFAULT NULL,
        currency_code VARCHAR(10)    DEFAULT NULL,
        language_code VARCHAR(20)    DEFAULT NULL,
        image         VARCHAR(2000)  DEFAULT NULL,
        attributes    JSON           DEFAULT NULL,
        program_ids   JSON           DEFAULT NULL,
        standard_ids  JSON           DEFAULT NULL,
        raw_data      JSON           DEFAULT NULL,
        imported_at   TIMESTAMP      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY idx_feed_id (feed_id),
        KEY idx_program_id (program_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci",

    'airlines' => "CREATE TABLE IF NOT EXISTS {$p}airlines (
        id           INT UNSIGNED  NOT NULL,
        name         VARCHAR(255)  DEFAULT NULL,
        alias        VARCHAR(255)  DEFAULT NULL,
        callsign     VARCHAR(100)  DEFAULT NULL,
        country      VARCHAR(100)  DEFAULT NULL,
        country_code VARCHAR(10)   DEFAULT NULL,
        iata         VARCHAR(10)   DEFAULT NULL,
        icao         VARCHAR(10)   DEFAULT NULL,
        logo         VARCHAR(2000) DEFAULT NULL,
        raw_data     JSON          DEFAULT NULL,
        imported_at  TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci",

    'airports' => "CREATE TABLE IF NOT EXISTS {$p}airports (
        id            INT UNSIGNED   NOT NULL,
        name          VARCHAR(255)   DEFAULT NULL,
        city          VARCHAR(255)   DEFAULT NULL,
        country       VARCHAR(100)   DEFAULT NULL,
        country_code  VARCHAR(10)    DEFAULT NULL,
        iata          VARCHAR(10)    DEFAULT NULL,
        icao          VARCHAR(10)    DEFAULT NULL,
        latitude      DECIMAL(10,7)  DEFAULT NULL,
        longitude     DECIMAL(10,7)  DEFAULT NULL,
        altitude      DECIMAL(10,2)  DEFAULT NULL,
        timezone      DECIMAL(5,2)   DEFAULT NULL,
        dst           VARCHAR(5)     DEFAULT NULL,
        timezone_name VARCHAR(100)   DEFAULT NULL,
        raw_data      JSON           DEFAULT NULL,
        imported_at   TIMESTAMP      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci",

    'currencies' => "CREATE TABLE IF NOT EXISTS {$p}currencies (
        code                  VARCHAR(10)    NOT NULL,
        exchange_rate_to_euro DECIMAL(20,10) DEFAULT NULL,
        date_modified         VARCHAR(50)    DEFAULT NULL,
        raw_data              JSON           DEFAULT NULL,
        imported_at           TIMESTAMP      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (code)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci",

    'public_holidays' => "CREATE TABLE IF NOT EXISTS {$p}public_holidays (
        id           VARCHAR(64)  NOT NULL,
        start_date   DATE         DEFAULT NULL,
        end_date     DATE         DEFAULT NULL,
        country_code VARCHAR(10)  DEFAULT NULL,
        local_name   VARCHAR(255) DEFAULT NULL,
        name         VARCHAR(255) DEFAULT NULL,
        raw_data     JSON         DEFAULT NULL,
        imported_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY idx_country_date (country_code, start_date)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci",

    'regions' => "CREATE TABLE IF NOT EXISTS {$p}regions (
        code         VARCHAR(20)  NOT NULL,
        name         VARCHAR(255) DEFAULT NULL,
        country_code VARCHAR(10)  DEFAULT NULL,
        raw_data     JSON         DEFAULT NULL,
        imported_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (code),
        KEY idx_country_code (country_code)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci",

    'productfeed_standards' => "CREATE TABLE IF NOT EXISTS {$p}productfeed_standards (
        id          INT UNSIGNED NOT NULL,
        name        VARCHAR(255) DEFAULT NULL,
        raw_data    JSON         DEFAULT NULL,
        imported_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci",

    'annual_events' => "CREATE TABLE IF NOT EXISTS {$p}annual_events (
        id          INT UNSIGNED NOT NULL,
        name        VARCHAR(255) DEFAULT NULL,
        restricted  TINYINT(1)   DEFAULT NULL,
        raw_data    JSON         DEFAULT NULL,
        imported_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci",

    'categories' => "CREATE TABLE IF NOT EXISTS {$p}categories (
        id          INT UNSIGNED NOT NULL,
        name        VARCHAR(255) DEFAULT NULL,
        type        JSON         DEFAULT NULL,
        raw_data    JSON         DEFAULT NULL,
        imported_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci",

    'device_browsers' => "CREATE TABLE IF NOT EXISTS {$p}device_browsers (
        id            INT UNSIGNED NOT NULL,
        name          VARCHAR(255) DEFAULT NULL,
        minor_version INT UNSIGNED DEFAULT NULL,
        major_version INT UNSIGNED DEFAULT NULL,
        brand         VARCHAR(255) DEFAULT NULL,
        raw_data      JSON         DEFAULT NULL,
        imported_at   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci",

    'device_models' => "CREATE TABLE IF NOT EXISTS {$p}device_models (
        id          INT UNSIGNED NOT NULL,
        name        VARCHAR(255) DEFAULT NULL,
        raw_data    JSON         DEFAULT NULL,
        imported_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci",

    'device_platforms' => "CREATE TABLE IF NOT EXISTS {$p}device_platforms (
        id          INT UNSIGNED NOT NULL,
        name        VARCHAR(255) DEFAULT NULL,
        version     VARCHAR(100) DEFAULT NULL,
        brand       VARCHAR(255) DEFAULT NULL,
        raw_data    JSON         DEFAULT NULL,
        imported_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci",

    'device_types' => "CREATE TABLE IF NOT EXISTS {$p}device_types (
        id          INT UNSIGNED NOT NULL,
        name        VARCHAR(255) DEFAULT NULL,
        raw_data    JSON         DEFAULT NULL,
        imported_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci",

    'publishers' => "CREATE TABLE IF NOT EXISTS {$p}publishers (
        id          INT UNSIGNED NOT NULL,
        name        VARCHAR(255) DEFAULT NULL,
        raw_data    JSON         DEFAULT NULL,
        imported_at TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci",

    'news' => "CREATE TABLE IF NOT EXISTS {$p}news (
        id           INT UNSIGNED NOT NULL,
        publisher_id INT UNSIGNED NOT NULL,
        source       VARCHAR(50)  DEFAULT NULL,
        program_id   INT UNSIGNED DEFAULT NULL,
        date         VARCHAR(50)  DEFAULT NULL,
        language_id  INT UNSIGNED DEFAULT NULL,
        locale_ids   JSON         DEFAULT NULL,
        type         VARCHAR(50)  DEFAULT NULL,
        title        VARCHAR(500) DEFAULT NULL,
        text         MEDIUMTEXT   DEFAULT NULL,
        raw_data     JSON         DEFAULT NULL,
        imported_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY idx_publisher_id (publisher_id),
        KEY idx_program_id (program_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci",

];

// Existing installs: rename unprefixed tables to their prefixed name (keeps data).
$tableExists = function (string $table) use ($pdo): bool {
    $stmt = $pdo->prepare('SHOW TABLES LIKE ?');
    $stmt->execute([addcslashes($table, '_%\\')]);
    return $stmt->fetchColumn() !== false;
};
if ($p !== '') {
    foreach (array_keys($tables) as $name) {
        if ($tableExists($name) && !$tableExists($p . $name)) {
            $pdo->exec("RENAME TABLE `$name` TO `{$p}{$name}`");
            echo "  ↻ Renamed '$name' → '{$p}{$name}'\n";
        }
    }
}

foreach ($tables as $name => $sql) {
    $pdo->exec($sql);
    echo "  ✓ Table '{$p}{$name}' ready\n";
}

// Column migrations — safe to re-run on existing installs (IF NOT EXISTS).
$migrations = [
    "ALTER TABLE {$p}productfeeds DROP COLUMN IF EXISTS locale_id",
    "ALTER TABLE {$p}productfeeds ADD COLUMN IF NOT EXISTS locale_ids JSON DEFAULT NULL AFTER language_code",
];
foreach ($migrations as $sql) {
    $pdo->exec($sql);
}
echo "\nMigrations applied.\n";

echo "Database setup complete. Run auth_setup.php next.\n";

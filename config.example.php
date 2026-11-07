<?php
declare(strict_types=1);

/**
 * Daisycon API Import — Configuration
 *
 * Before running for the first time:
 *   1. Copy config.example.php to config.php, then fill in oauth.client_id (and optionally client_secret) from your Daisycon developer app.
 *      Create one at: https://daisycon.com/en/developers/api/authentication/create-app/
 *   2. Run: php setup.php       — creates the database tables
 *   3. Run: php auth_setup.php  — OAuth PKCE flow; saves tokens.json
 *   4. Run: php import.php      — imports all data
 */

return [

    'oauth' => [
        'client_id'     => '',  // Required: your Daisycon app client_id
        'client_secret' => '',  // Optional: your Daisycon app client_secret
        'token_url'     => 'https://login.daisycon.com/oauth/access-token',
        'authorize_url' => 'https://login.daisycon.com/oauth/authorize',
        'redirect_uri'  => 'https://login.daisycon.com/oauth/cli',
        'tokens_file'   => __DIR__ . '/tokens.json',
    ],

    'api' => [
        'base_url'           => 'https://services.daisycon.com',
        'rate_limit_per_min' => 45,   // Stay safely under the 50 req/min hard limit
        'per_page'           => 1000, // Max allowed per page
    ],

    'import' => [
        // Media IDs to import, e.g. [123456, 234567]. Only these media (and their
        // subscriptions, programs, feeds, products and news) are imported.
        // Leave empty to import all active media. Overridden by --media=ID,ID on the CLI.
        'media_ids' => [],

        // Productfeed locale codes to include, e.g. ['nl_NL', 'nl_BE', 'en_GB'].
        // Matched against productfeed.locale_id via the locales table.
        // Leave empty to auto-detect from your active media websites (by language).
        'locale_codes' => ['nl-NL'],

        // Program IDs to skip entirely (feeds, products, and news are skipped too).
        'exclude_program_ids'    => [13196, 14979, 15332, 15967, 17619, 18917, 19043, 19234],

        // Advertiser IDs whose programs are skipped entirely.
        'exclude_advertiser_ids' => [3570, 3689, 9779],
    ],

    'db' => [
        'host'    => 'localhost',
        'name'    => 'your_database',
        'user'    => 'your_db_user',
        'pass'    => 'your_db_password',
        'charset' => 'utf8mb4',
        // Prepended to every table name (e.g. 'products' → 'cpa_network_daisycon_products').
        'table_prefix' => 'cpa_network_daisycon_',
    ],

];

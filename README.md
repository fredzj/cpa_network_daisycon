# CPA Network — Daisycon Import

PHP CLI scripts that import Daisycon publisher data (media, subscriptions, programs, product feeds, products) into a MySQL database via the Daisycon API.

## Setup

1. Copy the example config and fill in your credentials:
   ```sh
   cp config.example.php config.php
   ```
   - `oauth.client_id` / `oauth.client_secret`: from your [Daisycon developer app](https://daisycon.com/en/developers/api/authentication/create-app/)
   - `db.*`: your MySQL connection details
2. Create the database tables: `php setup.php`
3. Authorize with Daisycon (OAuth PKCE, writes `tokens.json`): `php auth_setup.php`
4. Run the import: `php import.php [--dry-run] [--skip-products] [--feed-format=csv|json|xml] [--media=ID,ID]`

`config.php` and `tokens.json` contain secrets and are git-ignored. Never commit them.

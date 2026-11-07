<?php
declare(strict_types=1);

/**
 * Daisycon API Import — OAuth Setup (run once)
 *
 * Usage: php auth_setup.php
 *
 * Guides you through the PKCE OAuth authorization_code flow and saves
 * tokens to tokens.json. Re-run if your refresh_token ever expires.
 */

$config = require __DIR__ . '/config.php';
$oauth  = $config['oauth'];

// ── Pre-flight checks ────────────────────────────────────────────────────────

if (empty($oauth['client_id'])) {
    echo "ERROR: oauth.client_id is not set in config.php.\n";
    echo "Create an app at: https://daisycon.com/en/developers/api/authentication/create-app/\n";
    exit(1);
}

// ── PKCE: generate code_verifier and code_challenge ──────────────────────────

$codeVerifier  = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
$codeChallenge = rtrim(strtr(base64_encode(hash('sha256', $codeVerifier, true)), '+/', '-_'), '=');

// ── Build authorization URL ───────────────────────────────────────────────────

$authUrl = $oauth['authorize_url'] . '?' . http_build_query([
    'client_id'             => $oauth['client_id'],
    'response_type'         => 'code',
    'redirect_uri'          => $oauth['redirect_uri'],
    'code_challenge'        => $codeChallenge,
    'code_challenge_method' => 'S256',
]);

echo "╔══════════════════════════════════════════════════════════╗\n";
echo "║         Daisycon API — OAuth Authorization Setup         ║\n";
echo "╚══════════════════════════════════════════════════════════╝\n\n";
echo "Step 1 — Open this URL in your browser:\n\n";
echo "  $authUrl\n\n";
echo "Step 2 — Log in with your Daisycon account and authorize the app.\n\n";
echo "Step 3 — After authorization, you will see a code on screen.\n";
echo "         Paste it here and press Enter:\n\n> ";

$code = trim((string) fgets(STDIN));

if ($code === '') {
    echo "\nERROR: No code provided. Aborting.\n";
    exit(1);
}

// ── Exchange code for tokens ──────────────────────────────────────────────────

echo "\nExchanging authorization code for tokens ...\n";

$postFields = [
    'grant_type'    => 'authorization_code',
    'client_id'     => $oauth['client_id'],
    'redirect_uri'  => $oauth['redirect_uri'],
    'code'          => $code,
    'code_verifier' => $codeVerifier,
];

if (!empty($oauth['client_secret'])) {
    $postFields['client_secret'] = $oauth['client_secret'];
}

$ch = curl_init($oauth['token_url']);
curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => http_build_query($postFields),
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded', 'Accept: application/json'],
    CURLOPT_TIMEOUT        => 30,
]);
$body     = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlErr  = curl_error($ch);
curl_close($ch);

if ($curlErr) {
    echo "ERROR: cURL error: $curlErr\n";
    exit(1);
}

if ($httpCode !== 200) {
    echo "ERROR: Token endpoint returned HTTP $httpCode:\n$body\n";
    exit(1);
}

$tokens = json_decode((string) $body, true);
if (empty($tokens['access_token'])) {
    echo "ERROR: Unexpected token response:\n$body\n";
    exit(1);
}

// Store absolute expiry timestamp so import.php can refresh proactively.
$tokens['expires_at'] = time() + (int) ($tokens['expires_in'] ?? 3600);

// ── Persist tokens ────────────────────────────────────────────────────────────

file_put_contents($oauth['tokens_file'], json_encode($tokens, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

echo "\n✓ Tokens saved to: {$oauth['tokens_file']}\n";
echo "  Access token expires : " . date('Y-m-d H:i:s', $tokens['expires_at']) . "\n";
echo "  Has refresh_token    : " . (!empty($tokens['refresh_token']) ? 'yes' : 'no') . "\n";
echo "\nAll done. Run php import.php to start importing.\n";

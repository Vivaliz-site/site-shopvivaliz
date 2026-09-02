<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$authorize = (string)file_get_contents($root . '/auth/google-ads-authorize.php');
$callback = (string)file_get_contents($root . '/auth/google-callback.php');

$checks = [
    "purpose gmail_readonly" => str_contains($authorize, "'gmail_readonly'"),
    "gmail readonly scope" => str_contains($authorize, 'https://www.googleapis.com/auth/gmail.readonly'),
    "gmail disables granted scope union" => str_contains($authorize, "'include_granted_scopes' => \$purpose === 'gmail_readonly' ? 'false' : 'true'"),
    "purpose signed into state" => str_contains($authorize, "'purpose' => \$purpose"),
    "callback identifies gmail purpose" => str_contains($callback, "\$googleOauthPurpose === 'gmail_readonly'"),
    "callback keeps refresh token capture" => str_contains($callback, 'sv_google_ads_write_pending_refresh_token($adsJob, $refreshToken)'),
];

foreach ($checks as $name => $ok) {
    if (!$ok) {
        fwrite(STDERR, "FAIL: {$name}\n");
        exit(1);
    }
}

echo "PASS google-oauth-gmail-authorize\n";

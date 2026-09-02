<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/social-auth.php';

if (!sv_social_google_is_configured()) {
    http_response_code(503);
    exit('Google OAuth is not configured.');
}

$job = strtolower(trim((string)($_GET['job'] ?? '')));
$purpose = strtolower(trim((string)($_GET['purpose'] ?? 'google_ads')));
if (!in_array($purpose, ['google_ads', 'gmail_readonly'], true)) {
    http_response_code(400);
    exit('Invalid authorization purpose.');
}
if (!preg_match('/^[a-f0-9]{32}$/', $job)) {
    http_response_code(400);
    exit('Invalid authorization job.');
}

// Use a short-lived signed state instead of relying on the PHP session cookie.
// Google redirects back cross-site and a SameSite=Strict session cookie may not be
// sent on that first request, which would otherwise make the callback lose the job.
$payload = [
    'v' => 1,
    'job' => $job,
    'purpose' => $purpose,
    'ts' => time(),
    'nonce' => bin2hex(random_bytes(12)),
];
$payloadJson = (string)json_encode($payload, JSON_UNESCAPED_SLASHES);
$payloadB64 = rtrim(strtr(base64_encode($payloadJson), '+/', '-_'), '=');
$signature = hash_hmac('sha256', $payloadB64, sv_social_env('GOOGLE_OAUTH_CLIENT_SECRET'));
$state = 'gads1.' . $payloadB64 . '.' . $signature;

$url = 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query([
    'client_id' => sv_social_env('GOOGLE_OAUTH_CLIENT_ID'),
    'redirect_uri' => sv_social_callback_url('google'),
    'response_type' => 'code',
    'scope' => $purpose === 'gmail_readonly'
        ? 'https://www.googleapis.com/auth/gmail.readonly'
        : 'https://www.googleapis.com/auth/adwords',
    'state' => $state,
    'access_type' => 'offline',
    'include_granted_scopes' => $purpose === 'gmail_readonly' ? 'false' : 'true',
    'prompt' => 'consent',
]);

header('Cache-Control: no-store, max-age=0');
header('Pragma: no-cache');
header('Location: ' . $url, true, 302);
exit;

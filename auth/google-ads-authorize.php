<?php
declare(strict_types=1);

session_start();

require_once __DIR__ . '/../includes/social-auth.php';

if (!sv_social_google_is_configured()) {
    http_response_code(503);
    exit('Google OAuth is not configured.');
}

$job = strtolower(trim((string)($_GET['job'] ?? '')));
if (!preg_match('/^[a-f0-9]{32}$/', $job)) {
    http_response_code(400);
    exit('Invalid authorization job.');
}

$state = bin2hex(random_bytes(24));
$_SESSION['social_oauth']['google_ads'] = [
    'state' => $state,
    'job' => $job,
    'created_at' => time(),
];

$url = 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query([
    'client_id' => sv_social_env('GOOGLE_OAUTH_CLIENT_ID'),
    'redirect_uri' => sv_social_callback_url('google'),
    'response_type' => 'code',
    'scope' => 'https://www.googleapis.com/auth/adwords',
    'state' => $state,
    'access_type' => 'offline',
    'include_granted_scopes' => 'true',
    'prompt' => 'consent',
]);

header('Cache-Control: no-store, max-age=0');
header('Pragma: no-cache');
header('Location: ' . $url, true, 302);
exit;

<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/social-auth.php';

if (!sv_social_google_is_configured()) {
    http_response_code(503);
    exit('Google OAuth is not configured.');
}

$project = trim((string)(getenv('GOOGLE_CLOUD_PROJECT_NUMBER') ?: getenv('GOOGLE_CLOUD_PROJECT_ID') ?: ''));
if (!preg_match('/^[0-9]{6,20}$/', $project)) {
    http_response_code(503);
    exit('Google Cloud project number is not configured.');
}

$payload = [
    'v' => 1,
    'action' => 'enable_google_ads_api',
    'project' => $project,
    'ts' => time(),
    'nonce' => bin2hex(random_bytes(12)),
];
$payloadJson = (string)json_encode($payload, JSON_UNESCAPED_SLASHES);
$payloadB64 = rtrim(strtr(base64_encode($payloadJson), '+/', '-_'), '=');
$signature = hash_hmac('sha256', $payloadB64, sv_social_env('GOOGLE_OAUTH_CLIENT_SECRET'));
$state = 'gcloud1.' . $payloadB64 . '.' . $signature;

$params = [
    'client_id' => sv_social_env('GOOGLE_OAUTH_CLIENT_ID'),
    'redirect_uri' => sv_social_callback_url('google'),
    'response_type' => 'code',
    'scope' => 'https://www.googleapis.com/auth/cloud-platform',
    'state' => $state,
    'access_type' => 'online',
    'include_granted_scopes' => 'false',
    // The user already granted this scope in the immediately preceding flow.
    // Reuse that grant without presenting a second consent screen when Google can do so.
    'prompt' => 'none',
];

$loginHint = trim((string)(getenv('GOOGLE_OAUTH_LOGIN_HINT') ?: ''));
if ($loginHint !== '' && filter_var($loginHint, FILTER_VALIDATE_EMAIL)) {
    $params['login_hint'] = $loginHint;
}

$url = 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query($params);
header('Cache-Control: no-store, max-age=0');
header('Pragma: no-cache');
header('Location: ' . $url, true, 302);
exit;
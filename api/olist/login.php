<?php
declare(strict_types=1);

/**
 * Olist/Tiny OAuth2 Authorization Code Flow
 * 1. GET /api/olist/login.php -> Redireciona para Tiny login
 * 2. Tiny redireciona para /api/olist/callback.php?code=...
 * 3. callback.php troca code por token e salva em .env
 */

set_time_limit(30);
error_reporting(E_ALL);
ini_set('display_errors', '0');

header('Content-Type: application/json; charset=utf-8');

$envFile = dirname(__DIR__, 2) . '/.env';
if (!is_file($envFile)) {
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => '.env file not found',
    ]);
    exit;
}

// Ler .env
$envContent = file_get_contents($envFile);
$env = [];
foreach (explode("\n", $envContent) as $line) {
    $line = trim($line);
    if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) continue;
    [$k, $v] = explode('=', $line, 2);
    $env[trim($k)] = trim(trim($v), '"\'');
}

$clientId = $env['OLIST_CLIENT_ID'] ?? $env['TINY_CLIENT_ID'] ?? '';
$redirectUri = $env['URL_REDIRCT_OLIST'] ?? 'https://dev.shopvivaliz.com.br/olist/callback.php';

if (!$clientId) {
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => 'OLIST_CLIENT_ID not configured in .env',
    ]);
    exit;
}

$authorizationUrl = 'https://accounts.tiny.com.br/realms/tiny/protocol/openid-connect/auth?' . http_build_query([
    'client_id' => $clientId,
    'redirect_uri' => $redirectUri,
    'response_type' => 'code',
    'scope' => 'openid email offline_access',
    'state' => bin2hex(random_bytes(16)),
]);

$_SESSION['oauth_state'] = $authorizationUrl;

http_response_code(200);
echo json_encode([
    'status' => 'ok',
    'message' => 'Open this URL in your browser to authorize',
    'authorization_url' => $authorizationUrl,
    'client_id' => substr($clientId, 0, 20) . '...',
    'redirect_uri' => $redirectUri,
    'next_step' => 'After authorization, your tokens will be automatically saved to .env',
]);

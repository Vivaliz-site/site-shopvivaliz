<?php
declare(strict_types=1);

/**
 * Olist/Tiny OAuth2 Callback Handler
 * Recebe authorization code do Tiny e troca por tokens
 * URL: /api/olist/callback.php?code=...&state=...
 */

set_time_limit(30);
error_reporting(E_ALL);
ini_set('display_errors', '0');

header('Content-Type: application/json; charset=utf-8');

$code = $_GET['code'] ?? '';
$error = $_GET['error'] ?? '';

if ($error) {
    http_response_code(400);
    echo json_encode([
        'status' => 'error',
        'message' => 'Authorization failed',
        'error' => $error,
        'error_description' => $_GET['error_description'] ?? 'Unknown error',
    ]);
    exit;
}

if (!$code) {
    http_response_code(400);
    echo json_encode([
        'status' => 'error',
        'message' => 'Authorization code not provided',
    ]);
    exit;
}

// Ler .env
$envFile = dirname(__DIR__, 2) . '/.env';
$envContent = file_get_contents($envFile);
$env = [];
foreach (explode("\n", $envContent) as $line) {
    $line = trim($line);
    if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) continue;
    [$k, $v] = explode('=', $line, 2);
    $env[trim($k)] = trim(trim($v), '"\'');
}

$clientId = $env['OLIST_CLIENT_ID'] ?? '';
$clientSecret = $env['OLIST_CLIENT_SECRET'] ?? '';
$redirectUri = $env['URL_REDIRCT_OLIST'] ?? 'https://dev.shopvivaliz.com.br/olist/callback.php';

if (!$clientId || !$clientSecret) {
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => 'Missing client credentials in .env',
    ]);
    exit;
}

// Trocar code por tokens
$ch = curl_init('https://accounts.tiny.com.br/realms/tiny/protocol/openid-connect/token');
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => http_build_query([
        'grant_type' => 'authorization_code',
        'code' => $code,
        'client_id' => $clientId,
        'client_secret' => $clientSecret,
        'redirect_uri' => $redirectUri,
    ]),
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 15,
    CURLOPT_CONNECTTIMEOUT => 10,
]);

$response = curl_exec($ch);
$httpCode = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
curl_close($ch);

$data = json_decode((string)$response, true) ?? [];

if ($httpCode !== 200) {
    http_response_code($httpCode >= 400 ? $httpCode : 500);
    echo json_encode([
        'status' => 'error',
        'message' => 'Token exchange failed',
        'http_code' => $httpCode,
        'error' => $data['error'] ?? 'unknown',
        'error_description' => $data['error_description'] ?? '',
    ]);
    exit;
}

if (empty($data['access_token'])) {
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => 'No access token in response',
        'response_excerpt' => substr((string)$response, 0, 200),
    ]);
    exit;
}

// Salvar tokens em .env
$newAccess = $data['access_token'];
$newRefresh = $data['refresh_token'] ?? '';

$replacements = [
    'OLIST_ACCESS_TOKEN' => $newAccess,
    'OLIST_REFRESH_TOKEN' => $newRefresh,
    'TINY_ACCESS_TOKEN' => $newAccess,
    'TINY_REFRESH_TOKEN' => $newRefresh,
];

$updatedEnv = $envContent;
foreach ($replacements as $key => $value) {
    if (preg_match('/^' . preg_quote($key, '/') . '=.*/m', $updatedEnv)) {
        $updatedEnv = preg_replace(
            '/^' . preg_quote($key, '/') . '=.*/m',
            $key . '=' . $value,
            $updatedEnv
        );
    } else {
        $updatedEnv .= "\n" . $key . '=' . $value;
    }
}

// Tentar salvar com tratamento de erro
$written = @file_put_contents($envFile, $updatedEnv);

if ($written === false) {
    http_response_code(500);
    echo json_encode([
        'status' => 'error',
        'message' => 'Token obtained from Tiny but failed to save to .env',
        'reason' => 'Check file permissions: ' . substr((string)shell_exec("ls -l {$envFile}"), 0, 100),
        'solution' => 'Run: chmod 666 ' . $envFile,
        'access_token_preview' => substr($newAccess, 0, 30) . '...',
        'refresh_token_preview' => $newRefresh ? substr($newRefresh, 0, 30) . '...' : 'null',
    ]);
    exit;
}

// Sucesso
http_response_code(200);
echo json_encode([
    'status' => 'ok',
    'message' => 'Tokens obtained and saved successfully',
    'timestamp' => date('c'),
    'bytes_written' => $written,
    'access_token_preview' => substr($newAccess, 0, 30) . '...',
    'refresh_token_preview' => $newRefresh ? substr($newRefresh, 0, 30) . '...' : 'null',
    'next_step' => 'Daemon will sync products automatically. No further action needed.',
]);

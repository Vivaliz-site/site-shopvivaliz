<?php
declare(strict_types=1);

/**
 * Forca renovacao de token Olist/Tiny com saida estruturada para uso
 * manual, em CI e em automacoes de observabilidade.
 */

set_time_limit(30);
error_reporting(E_ALL);
ini_set('display_errors', '1');

function svrt_out(string $status, string $message, array $extra = [], int $exitCode = 0): never
{
    $payload = array_merge([
        'status' => $status,
        'message' => $message,
        'timestamp' => date('c'),
    ], $extra);

    if (PHP_SAPI !== 'cli') {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code($exitCode === 0 ? 200 : 500);
    }

    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . PHP_EOL;
    exit($exitCode);
}

$envFile = __DIR__ . '/../../.env';
if (is_file($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) {
            continue;
        }
        [$k, $v] = explode('=', $line, 2);
        $k = trim($k);
        $v = trim(trim($v), '"\'');
        if ($k !== '' && getenv($k) === false) {
            putenv($k . '=' . $v);
            $_ENV[$k] = $v;
        }
    }
}

$clientId = getenv('OLIST_CLIENT_ID') ?: getenv('TINY_CLIENT_ID') ?: getenv('CLIENT_ID_API_OLIST');
$clientSecret = getenv('OLIST_CLIENT_SECRET') ?: getenv('TINY_CLIENT_SECRET') ?: getenv('CLIENT_SECRET_OLIST');
$refreshToken = getenv('OLIST_REFRESH_TOKEN') ?: getenv('TINY_REFRESH_TOKEN');

if (!$clientId || !$clientSecret || !$refreshToken) {
    svrt_out('error', 'Missing Olist credentials in .env', [
        'has_client_id' => $clientId !== false && $clientId !== '',
        'has_client_secret' => $clientSecret !== false && $clientSecret !== '',
        'has_refresh_token' => $refreshToken !== false && $refreshToken !== '',
    ], 2);
}

$ch = curl_init('https://accounts.tiny.com.br/realms/tiny/protocol/openid-connect/token');
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => http_build_query([
        'grant_type' => 'refresh_token',
        'refresh_token' => $refreshToken,
        'client_id' => $clientId,
        'client_secret' => $clientSecret,
    ]),
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 15,
    CURLOPT_CONNECTTIMEOUT => 10,
]);

$response = curl_exec($ch);
$httpCode = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
$curlError = curl_error($ch);
curl_close($ch);

if ($response === false) {
    svrt_out('error', 'Token refresh request failed at cURL layer', [
        'http_code' => $httpCode,
        'curl_error' => $curlError,
    ], 3);
}

if ($httpCode !== 200) {
    $decoded = json_decode((string)$response, true);
    $oauthError = is_array($decoded) ? (string)($decoded['error'] ?? '') : '';
    $oauthDescription = is_array($decoded) ? (string)($decoded['error_description'] ?? '') : '';
    svrt_out('error', 'Token refresh failed', [
        'http_code' => $httpCode,
        'oauth_error' => $oauthError,
        'oauth_error_description' => $oauthDescription,
        'is_invalid_grant' => $oauthError === 'invalid_grant',
        'response_excerpt' => substr((string)$response, 0, 300),
    ], 5);
}

$data = json_decode((string)$response, true);
if (!is_array($data) || empty($data['access_token'])) {
    svrt_out('error', 'OAuth endpoint returned 200 without access_token', [
        'http_code' => $httpCode,
        'response_excerpt' => substr((string)$response, 0, 300),
    ], 4);
}

$newAccess = (string)$data['access_token'];
$newRefresh = (string)($data['refresh_token'] ?? $refreshToken);
$envContent = is_file($envFile) ? (string)file_get_contents($envFile) : '';
$replacements = [
    'OLIST_ACCESS_TOKEN' => $newAccess,
    'OLIST_REFRESH_TOKEN' => $newRefresh,
    'TINY_ACCESS_TOKEN' => $newAccess,
    'TINY_REFRESH_TOKEN' => $newRefresh,
];

foreach ($replacements as $key => $value) {
    if (preg_match('/^' . preg_quote($key, '/') . '=.*/m', $envContent)) {
        $envContent = (string)preg_replace(
            '/^' . preg_quote($key, '/') . '=.*/m',
            $key . '=' . $value,
            $envContent
        );
    } else {
        $envContent .= rtrim($envContent) === '' ? '' : PHP_EOL;
        $envContent .= $key . '=' . $value;
    }
}

file_put_contents($envFile, $envContent);

svrt_out('ok', 'Tokens refreshed and saved', [
    'http_code' => $httpCode,
    'refresh_rotated' => isset($data['refresh_token']) && $data['refresh_token'] !== '',
    'access_token_preview' => substr($newAccess, 0, 10) . '...',
]);

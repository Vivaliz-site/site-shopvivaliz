<?php
declare(strict_types=1);

function svi_connect_load_env(string $path): void
{
    if (!is_file($path) || !is_readable($path)) {
        return;
    }
    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
            continue;
        }
        [$key, $value] = explode('=', $line, 2);
        $key = trim($key);
        $value = trim(trim($value), "\"'");
        if ($key !== '' && getenv($key) === false) {
            putenv($key . '=' . $value);
            $_ENV[$key] = $value;
        }
    }
}

svi_connect_load_env(dirname(__DIR__) . '/.env');

$clientId = getenv('OLIST_CLIENT_ID') ?: getenv('TINY_CLIENT_ID') ?: getenv('CLIENT_ID_API_OLIST') ?: '';
$redirectUri = getenv('OLIST_REDIRECT_URI')
    ?: getenv('URL_REDIRCT_OLIST')
    ?: getenv('TINY_REDIRECT_URI')
    ?: 'https://dev.shopvivaliz.com.br/olist/callback.php';

if ($clientId === '') {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'OLIST_CLIENT_ID não configurado';
    exit;
}

$authUrl = 'https://accounts.tiny.com.br/realms/tiny/protocol/openid-connect/auth?' . http_build_query([
    'client_id' => $clientId,
    'redirect_uri' => $redirectUri,
    'response_type' => 'code',
    'scope' => 'openid offline_access',
    'prompt' => 'consent',
]);

error_log('[Olist Connect] Redirecting to OAuth authorize endpoint');
header('Location: ' . $authUrl, true, 302);
exit;

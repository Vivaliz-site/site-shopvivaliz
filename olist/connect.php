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

function svi_connect_credential(string ...$keys): string
{
    foreach ($keys as $key) {
        $value = trim((string)(getenv($key) ?: ''));
        // Tiny client ids/secrets are long values. Ignore one-character
        // placeholders left by old installers and use the valid alias.
        if (strlen($value) >= 16) {
            return $value;
        }
    }
    return '';
}

function svi_connect_redirect_uri(): string
{
    $candidate = getenv('OLIST_REDIRECT_URI') ?: getenv('URL_REDIRCT_OLIST') ?: getenv('TINY_REDIRECT_URI') ?: '';
    $host = strtolower((string)(parse_url($candidate, PHP_URL_HOST) ?: ''));
    if ($candidate !== '' && $host === 'shopvivaliz.com.br' && (string)(parse_url($candidate, PHP_URL_PATH) ?: '') === '/olist/callback.php') {
        return $candidate;
    }
    return 'https://shopvivaliz.com.br/olist/callback.php';
}

svi_connect_load_env(dirname(__DIR__) . '/.env');

$clientId = svi_connect_credential('OLIST_CLIENT_ID', 'TINY_CLIENT_ID', 'CLIENT_ID_API_OLIST');
$redirectUri = svi_connect_redirect_uri();

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

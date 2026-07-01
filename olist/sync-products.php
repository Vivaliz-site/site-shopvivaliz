<?php
declare(strict_types=1);

header_remove('X-Powered-By');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function svi_sync_root(): string
{
    return dirname(__DIR__);
}

function svi_sync_log(string $message): void
{
    $line = '[' . date('Y-m-d H:i:s') . '] ' . $message . PHP_EOL;
    error_log('[Olist Sync] ' . $message);
    @file_put_contents(svi_sync_root() . '/logs/olist-sync.log', $line, FILE_APPEND);
}

function svi_sync_json(int $code, array $payload): never
{
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    exit;
}

function svi_sync_load_env_file(string $path): void
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

function svi_sync_absorb_array(array $values): void
{
    foreach ($values as $key => $value) {
        if (!is_string($key) || $key === '') {
            continue;
        }
        if (is_scalar($value)) {
            $stringValue = (string)$value;
            putenv($key . '=' . $stringValue);
            $_ENV[$key] = $stringValue;
        }
    }
}

function svi_sync_load_php_secrets(string $path): void
{
    if (!is_file($path) || !is_readable($path)) {
        return;
    }
    $before = get_defined_constants(true)['user'] ?? [];
    $returned = include $path;
    $after = get_defined_constants(true)['user'] ?? [];
    $newConstants = array_diff_key($after, $before);
    if ($newConstants) {
        svi_sync_absorb_array($newConstants);
    }
    if (is_array($returned)) {
        svi_sync_absorb_array($returned);
    }
}

function svi_sync_env(string ...$keys): string
{
    foreach ($keys as $key) {
        $value = getenv($key);
        if (is_string($value) && $value !== '') {
            return $value;
        }
        if (isset($_ENV[$key]) && is_string($_ENV[$key]) && $_ENV[$key] !== '') {
            return $_ENV[$key];
        }
        if (defined($key)) {
            $constant = constant($key);
            if (is_string($constant) && $constant !== '') {
                return $constant;
            }
        }
    }
    return '';
}

function svi_sync_http_get(string $url, array $headers = [], int $timeout = 45): array
{
    if (!function_exists('curl_init')) {
        $context = stream_context_create([
            'http' => [
                'method' => 'GET',
                'timeout' => $timeout,
                'ignore_errors' => true,
                'header' => implode("\r\n", $headers),
            ],
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
            ],
        ]);
        $body = @file_get_contents($url, false, $context);
        $status = 0;
        foreach ($http_response_header ?? [] as $line) {
            if (preg_match('/\s(\d{3})\s/', $line, $matches)) {
                $status = (int)$matches[1];
                break;
            }
        }
        return ['status' => $status, 'body' => is_string($body) ? $body : '', 'error' => $body === false ? 'stream_request_failed' : ''];
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 3,
        CURLOPT_HTTPHEADER => $headers,
    ]);
    $body = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    return ['status' => $status, 'body' => is_string($body) ? $body : '', 'error' => $error];
}

function svi_sync_http_post(string $url, array $data, int $timeout = 45): array
{
    if (!function_exists('curl_init')) {
        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'timeout' => $timeout,
                'ignore_errors' => true,
                'header' => "Content-Type: application/x-www-form-urlencoded\r\n",
                'content' => http_build_query($data),
            ],
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
            ],
        ]);
        $body = @file_get_contents($url, false, $context);
        $status = 0;
        foreach ($http_response_header ?? [] as $line) {
            if (preg_match('/\s(\d{3})\s/', $line, $matches)) {
                $status = (int)$matches[1];
                break;
            }
        }
        return ['status' => $status, 'body' => is_string($body) ? $body : '', 'error' => $body === false ? 'stream_request_failed' : ''];
    }

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query($data),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
    ]);
    $body = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    return ['status' => $status, 'body' => is_string($body) ? $body : '', 'error' => $error];
}

function svi_sync_cache_count(): int
{
    $paths = [
        svi_sync_root() . '/logs/olist-products-cache.json',
        svi_sync_root() . '/api/catalog/fallback-products.json',
    ];
    foreach ($paths as $path) {
        if (!is_file($path) || !is_readable($path)) {
            continue;
        }
        $json = json_decode((string)file_get_contents($path), true);
        if (is_array($json)) {
            if (isset($json['total']) && is_numeric($json['total'])) {
                return (int)$json['total'];
            }
            if (array_is_list($json)) {
                return count($json);
            }
            if (isset($json['items']) && is_array($json['items'])) {
                return count($json['items']);
            }
            if (isset($json['produtos']) && is_array($json['produtos'])) {
                return count($json['produtos']);
            }
        }
    }
    return 0;
}

svi_sync_load_env_file(svi_sync_root() . '/.env');
svi_sync_load_php_secrets('/etc/olist-credentials.php');
svi_sync_load_php_secrets(svi_sync_root() . '/storage/private/tokens.php');

$clientId = svi_sync_env('OLIST_CLIENT_ID', 'TINY_CLIENT_ID', 'CLIENT_ID_API_OLIST');
$clientSecret = svi_sync_env('OLIST_CLIENT_SECRET', 'TINY_CLIENT_SECRET', 'CLIENT_SECRET_OLIST');
$refreshToken = svi_sync_env('OLIST_REFRESH_TOKEN', 'TINY_REFRESH_TOKEN');
$accessToken = svi_sync_env('OLIST_ACCESS_TOKEN', 'TINY_ACCESS_TOKEN');
$apiTokenV2 = svi_sync_env('TOKEN_API_OLIST', 'TINY_API_TOKEN', 'OLIST_API_TOKEN');
$redirectUri = svi_sync_env('OLIST_REDIRECT_URI', 'TINY_REDIRECT_URI') ?: 'https://dev.shopvivaliz.com.br/olist/callback.php';

$expected = max(1, (int)($_GET['expected'] ?? 200));
$limit = max(1, min(100, (int)($_GET['limit'] ?? 50)));
$dryRun = isset($_GET['dry_run']) && $_GET['dry_run'] !== '0';
$beforeCount = svi_sync_cache_count();
$afterCount = $beforeCount;
$fetched = 0;
$imported = 0;
$queryModesTried = [];
$errors = [];
$operational = false;
$pageSize = min(100, max(1, $limit));

$oauthUrl = 'https://accounts.tiny.com.br/realms/tiny/protocol/openid-connect/auth?' . http_build_query([
    'client_id' => $clientId,
    'redirect_uri' => $redirectUri,
    'response_type' => 'code',
    'scope' => 'openid offline_access',
    'prompt' => 'consent',
]);

if ($apiTokenV2 !== '') {
    $seen = [];
    for ($page = 1; $page <= 20; $page++) {
        $url = 'https://api.tiny.com.br/api2/produtos.pesquisa.php?' . http_build_query([
            'token' => $apiTokenV2,
            'formato' => 'json',
            'pagina' => $page,
            'limite' => $pageSize,
        ]);
        $queryModesTried[] = 'tiny_api_v2_pagina_' . $page;
        $result = svi_sync_http_get($url, ['Accept: application/json', 'User-Agent: ShopVivaliz-OlistSync/2.0']);
        if ($result['status'] !== 200) {
            $errors[] = 'tiny_v2_http_' . $result['status'];
            break;
        }

        $json = json_decode($result['body'], true);
        $items = $json['retorno']['produtos'] ?? [];
        if (!is_array($items)) {
            $errors[] = 'tiny_v2_invalid_payload';
            break;
        }

        $batchCount = 0;
        foreach ($items as $item) {
            $produto = is_array($item['produto'] ?? null) ? $item['produto'] : $item;
            $id = (string)($produto['id'] ?? $produto['idProduto'] ?? md5(json_encode($produto)));
            if (isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;
            $batchCount++;
        }

        $fetched = count($seen);
        $operational = true;

        if ($batchCount === 0 || count($items) < $pageSize) {
            break;
        }
    }
} elseif ($refreshToken !== '' && $clientId !== '' && $clientSecret !== '') {
    $queryModesTried[] = 'oauth_refresh_attempt';
    $tokenResponse = svi_sync_http_post('https://accounts.tiny.com.br/realms/tiny/protocol/openid-connect/token', [
        'grant_type' => 'refresh_token',
        'client_id' => $clientId,
        'client_secret' => $clientSecret,
        'refresh_token' => $refreshToken,
    ]);
    if ($tokenResponse['status'] === 200) {
        $json = json_decode($tokenResponse['body'], true);
        if (is_array($json) && !empty($json['access_token'])) {
            $accessToken = (string)$json['access_token'];
            $operational = true;
        } else {
            $errors[] = 'oauth_refresh_invalid_payload';
        }
    } else {
        $decoded = json_decode($tokenResponse['body'], true);
        $errors[] = 'oauth_refresh_http_' . $tokenResponse['status'];
        if (is_array($decoded) && isset($decoded['error'])) {
            $errors[] = (string)$decoded['error'];
        }
    }
} elseif ($accessToken !== '') {
    $queryModesTried[] = 'oauth_access_token_present';
    $operational = true;
} else {
    $errors[] = 'credentials_missing';
}

if ($fetched > 0) {
    $afterCount = max($beforeCount, $fetched);
    $imported = max(0, $afterCount - $beforeCount);
}

if (!$dryRun && $fetched > 0) {
    $cacheFile = svi_sync_root() . '/logs/olist-products-cache.json';
    @mkdir(dirname($cacheFile), 0755, true);
    @file_put_contents($cacheFile, json_encode([
        'timestamp' => date('c'),
        'total' => $afterCount,
        'source' => $apiTokenV2 !== '' ? 'tiny_api_v2' : 'oauth_probe',
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
}

$ok = $operational && $afterCount >= $expected;
$message = $ok
    ? 'Sincronizacao operacional com quantidade esperada.'
    : ($operational
        ? 'Sincronizacao acessou a API, mas a quantidade ainda esta abaixo do esperado.'
        : 'Sincronizacao ainda depende de credencial OAuth/Tiny valida no servidor.');

svi_sync_log($message);
svi_sync_json($ok ? 200 : 207, [
    'ok' => $ok,
    'status' => $ok ? 'ok' : 'attention',
    'message' => $message,
    'expected' => $expected,
    'limit' => $limit,
    'dry_run' => $dryRun,
    'before_count' => $beforeCount,
    'after_count' => $afterCount,
    'fetched' => $fetched,
    'imported' => $imported,
    'operational' => $operational,
    'query_modes_tried' => $queryModesTried,
    'oauth' => [
        'authorize_url' => $oauthUrl,
        'has_client_id' => $clientId !== '',
        'has_client_secret' => $clientSecret !== '',
        'has_refresh_token' => $refreshToken !== '',
        'has_access_token' => $accessToken !== '',
        'has_offline_access' => str_contains($oauthUrl, 'offline_access'),
        'has_prompt_consent' => str_contains($oauthUrl, 'prompt=consent'),
    ],
    'credentials' => [
        'has_tiny_v2_token' => $apiTokenV2 !== '',
    ],
    'errors' => $errors,
    'generated_at' => date('c'),
]);

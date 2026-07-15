<?php
declare(strict_types=1);

header_remove('X-Powered-By');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$root = dirname(__DIR__);

function sync_cache_fail(string $message, int $status = 500, array $extra = []): void
{
    http_response_code($status);
    echo json_encode(array_merge(['success' => false, 'error' => $message], $extra), JSON_UNESCAPED_UNICODE);
    exit;
}

function sync_cache_env_value(string $root, string $key): string
{
    $runtimeSecrets = $root . '/config/runtime-secrets.php';
    if (is_file($runtimeSecrets) && is_readable($runtimeSecrets)) {
        $secrets = require $runtimeSecrets;
        if (is_array($secrets) && isset($secrets[$key]) && is_scalar($secrets[$key])) {
            $value = trim((string)$secrets[$key]);
            if ($value !== '') {
                return $value;
            }
        }
    }

    $envFile = $root . '/.env';
    if (is_file($envFile) && is_readable($envFile)) {
        foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
                continue;
            }
            [$candidateKey, $value] = explode('=', $line, 2);
            if (trim($candidateKey) === $key) {
                return trim(trim($value), "\"'");
            }
        }
    }

    $tokensFile = $root . '/storage/private/tokens.json';
    if (is_file($tokensFile) && is_readable($tokensFile)) {
        $tokens = json_decode((string)file_get_contents($tokensFile), true);
        if (is_array($tokens) && isset($tokens[$key]) && is_scalar($tokens[$key])) {
            $value = trim((string)$tokens[$key]);
            if ($value !== '') {
                return $value;
            }
        }
    }

    $value = getenv($key);
    return is_string($value) ? trim($value) : '';
}

function sync_cache_tiny_token(string $root): string
{
    return sync_cache_env_value($root, 'OLIST_ACCESS_TOKEN')
        ?: sync_cache_env_value($root, 'TINY_ACCESS_TOKEN');
}

function sync_cache_write_atomic(string $path, array $payload): void
{
    $tempPath = $path . '.tmp';
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    if ($json === false) {
        sync_cache_fail('cache_encode_failed');
    }
    if (file_put_contents($tempPath, $json . PHP_EOL, LOCK_EX) === false) {
        @unlink($tempPath);
        sync_cache_fail('cache_write_failed');
    }
    if (!rename($tempPath, $path)) {
        @unlink($tempPath);
        sync_cache_fail('cache_replace_failed');
    }
}

if (!in_array(strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET'), ['GET', 'POST'], true)) {
    sync_cache_fail('method_not_allowed', 405);
}

$token = sync_cache_tiny_token($root);
if ($token === '') {
    sync_cache_fail('token_not_found');
}

$allProducts = [];
$offset = 0;
$page = 0;
$maxPages = 50;

while ($page < $maxPages) {
    $url = "https://api.tiny.com.br/public-api/v3/produtos?limit=100&offset={$offset}";
    $context = stream_context_create([
        'https' => [
            'method' => 'GET',
            'header' => "Authorization: Bearer {$token}\r\nAccept: application/json\r\n",
            'timeout' => 30,
            'ignore_errors' => true,
        ],
    ]);
    $response = @file_get_contents($url, false, $context);
    if ($response === false) {
        sync_cache_fail('tiny_request_failed', 502, ['offset' => $offset]);
    }

    $data = json_decode($response, true);
    if (!is_array($data)) {
        sync_cache_fail('tiny_invalid_json', 502, ['offset' => $offset]);
    }

    $items = $data['itens'] ?? [];
    if (!is_array($items) || $items === []) {
        break;
    }

    foreach ($items as $item) {
        if (!is_array($item) || ($item['situacao'] ?? null) !== 'A') {
            continue;
        }
        $item['estoque_disponivel'] = $item['estoque']['quantidade'] ?? 0;
        $allProducts[] = $item;
    }

    $page++;
    if (count($items) < 100) {
        break;
    }

    $offset += 100;
    usleep(300000);
}

$cacheFile = $root . '/storage/products-cache-ativos.json';
if (!is_dir(dirname($cacheFile)) && !mkdir(dirname($cacheFile), 0755, true) && !is_dir(dirname($cacheFile))) {
    sync_cache_fail('cache_directory_unavailable');
}

sync_cache_write_atomic($cacheFile, [
    'total' => count($allProducts),
    'timestamp' => date('c'),
    'source' => 'tiny-public-api-v3',
    'pages_fetched' => $page,
    'itens' => $allProducts,
]);

http_response_code(200);
echo json_encode([
    'success' => true,
    'total' => count($allProducts),
    'pages_fetched' => $page,
    'file' => 'storage/products-cache-ativos.json',
], JSON_UNESCAPED_UNICODE);

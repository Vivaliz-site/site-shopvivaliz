<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$root = dirname(__DIR__);
$storageDir = $root . '/storage';
if (!is_dir($storageDir)) {
    @mkdir($storageDir, 0775, true);
}
$lockFile = fopen($storageDir . '/cache-sync.lock', 'c+');
if (!$lockFile || !flock($lockFile, LOCK_EX | LOCK_NB)) {
    http_response_code(409);
    echo json_encode(['success' => false, 'error' => 'cache_sync_locked']);
    exit;
}

try {
    $token = getenv('TINY_ACCESS_TOKEN') ?: getenv('OLIST_ACCESS_TOKEN') ?: '';
    if ($token === '') {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'missing_token']);
        exit;
    }

    $items = [];
    $offset = 0;
    do {
        $url = "https://api.tiny.com.br/public-api/v3/produtos?limit=100&offset={$offset}";
        $ctx = stream_context_create(['http' => ['method' => 'GET', 'header' => "Authorization: Bearer {$token}\r\nAccept: application/json\r\n", 'timeout' => 30]]);
        $response = @file_get_contents($url, false, $ctx);
        if ($response === false) break;
        $data = json_decode($response, true);
        $pageItems = $data['itens'] ?? [];
        if (!is_array($pageItems) || $pageItems === []) break;
        foreach ($pageItems as $item) {
            if (($item['situacao'] ?? '') === 'A') {
                $item['estoque_disponivel'] = (int)($item['estoque']['quantidade'] ?? 0);
                $items[] = $item;
            }
        }
        $offset += 100;
    } while (count($pageItems) === 100);

    $payload = [
        'success' => true,
        'total' => count($items),
        'updated_at' => gmdate(DATE_ATOM),
        'itens' => $items,
        'items' => $items,
    ];
    $encoded = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    if (!is_string($encoded)) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'json_encode_failed']);
        exit;
    }

    $cacheFiles = [
        $root . '/storage/products-cache-ativos.json',
        $root . '/storage/cache/products-cache-ativos.json',
    ];
    $written = [];
    foreach ($cacheFiles as $cacheFile) {
        @mkdir(dirname($cacheFile), 0775, true);
        if (file_put_contents($cacheFile, $encoded, LOCK_EX) !== false) {
            $written[] = $cacheFile;
        }
    }

    foreach (glob($root . '/storage/cache/catalog-api/*.json') ?: [] as $file) {
        @unlink($file);
    }

    echo json_encode(['success' => true, 'total' => count($items), 'files' => $written], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} finally {
    flock($lockFile, LOCK_UN);
    if (is_resource($lockFile)) {
        fclose($lockFile);
    }
}

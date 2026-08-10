<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');

$root = dirname(__DIR__);
$lockFile = fopen($root . '/storage/cache-sync.lock', 'c+');
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

    $cacheFile = $root . '/storage/cache/products-cache-ativos.json';
    @mkdir(dirname($cacheFile), 0775, true);
    file_put_contents($cacheFile, json_encode([
        'success' => true,
        'total' => count($items),
        'updated_at' => gmdate(DATE_ATOM),
        'items' => $items,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT), LOCK_EX);
    echo json_encode(['success' => true, 'total' => count($items), 'file' => $cacheFile]);
} finally {
    flock($lockFile, LOCK_UN);
}
?>

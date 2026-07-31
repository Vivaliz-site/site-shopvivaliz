<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');

$root = dirname(__DIR__);
$token = '';

foreach (file($root . '/.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
    if (str_starts_with($line, 'OLIST_ACCESS_TOKEN=')) {
        $token = trim(explode('=', $line, 2)[1] ?? '');
        break;
    }
}

if (!$token) {
    http_response_code(500);
    exit(json_encode(['erro' => 'Token não encontrado']));
}

$all_products = [];
$offset = 0;

while (true) {
    $url = "https://api.tiny.com.br/public-api/v3/produtos?limit=100&offset={$offset}";
    $ctx = stream_context_create(['https' => ['method' => 'GET', 'header' => "Authorization: Bearer {$token}\r\n", 'timeout' => 30]]);
    $response = @file_get_contents($url, false, $ctx);

    if (!$response) break;

    $data = json_decode($response, true);
    if (!isset($data['itens']) || empty($data['itens'])) break;

    foreach ($data['itens'] as $item) {
        if ($item['situacao'] === 'A') {
            $item['estoque_disponivel'] = $item['estoque']['quantidade'] ?? 0;
            $all_products[] = $item;
        }
    }

    if (count($data['itens']) < 100) break;
    $offset += 100;
    usleep(300000);
}

$cache_file = $root . '/storage/products-cache-ativos.json';
@mkdir(dirname($cache_file), 0755, true);

file_put_contents($cache_file, json_encode(['total' => count($all_products), 'timestamp' => date('Y-m-d H:i:s'), 'itens' => $all_products], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

http_response_code(200);
echo json_encode(['success' => true, 'total' => count($all_products), 'file' => $cache_file]);
?>

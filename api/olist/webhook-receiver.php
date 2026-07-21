<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');

$root = dirname(__DIR__, 2);
$logDir = $root . '/logs';
$cacheFile = $root . '/storage/products-cache-ativos.json';
$logFile = $logDir . '/olist-webhook-receiver.log';
@mkdir($logDir, 0755, true);

function log_event(string $message): void
{
    global $logFile;
    @file_put_contents(
        $logFile,
        '[' . date('Y-m-d H:i:s') . '] ' . $message . PHP_EOL,
        FILE_APPEND | LOCK_EX
    );
}

function respond(int $status, array $payload): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function first_value(array $data, array $paths): mixed
{
    foreach ($paths as $path) {
        $value = $data;
        foreach (explode('.', $path) as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                $value = null;
                break;
            }
            $value = $value[$segment];
        }
        if ($value !== null && $value !== '') {
            return $value;
        }
    }
    return null;
}

function is_list_array(array $value): bool
{
    return $value === [] || array_keys($value) === range(0, count($value) - 1);
}

function collect_items(array $payload): array
{
    foreach (['itens', 'items', 'produtos', 'products', 'data', 'payload', 'registros'] as $key) {
        if (!isset($payload[$key]) || !is_array($payload[$key])) {
            continue;
        }
        $candidate = $payload[$key];
        if (is_list_array($candidate)) {
            return array_values(array_filter($candidate, 'is_array'));
        }
        foreach (['itens', 'items', 'produtos', 'products', 'registros'] as $nested) {
            if (isset($candidate[$nested]) && is_array($candidate[$nested]) && is_list_array($candidate[$nested])) {
                return array_values(array_filter($candidate[$nested], 'is_array'));
            }
        }
    }

    return is_list_array($payload)
        ? array_values(array_filter($payload, 'is_array'))
        : [$payload];
}

function normalize_item(array $item): array
{
    $sku = trim((string)(first_value($item, [
        'sku', 'codigo', 'produto_sku', 'produto.sku', 'produto.codigo',
        'product.sku', 'product.code', 'data.sku', 'data.codigo'
    ]) ?? ''));

    $id = trim((string)(first_value($item, [
        'id', 'idProduto', 'produto_id', 'produto.id', 'product.id', 'data.id'
    ]) ?? ''));

    $priceRaw = first_value($item, [
        'preco', 'price', 'preco_venda', 'valor', 'precos.preco',
        'produto.preco', 'produto.precos.preco', 'product.price',
        'data.preco', 'data.price'
    ]);

    $stockRaw = first_value($item, [
        'estoque_disponivel', 'estoque', 'quantity', 'quantidade',
        'estoque.quantidade', 'produto.estoque.quantidade',
        'product.stock', 'data.estoque', 'data.quantity'
    ]);

    return [
        'sku' => $sku,
        'id' => $id,
        'price' => is_numeric($priceRaw) ? (float)$priceRaw : null,
        'stock' => is_numeric($stockRaw) ? max(0, (int)$stockRaw) : null,
    ];
}

function update_cache(array $updates): array
{
    global $cacheFile;

    if (!is_file($cacheFile)) {
        return ['updated' => 0, 'missing' => count($updates), 'cache' => 'not_found'];
    }

    $fp = @fopen($cacheFile, 'c+');
    if (!$fp || !flock($fp, LOCK_EX)) {
        if (is_resource($fp)) {
            fclose($fp);
        }
        return ['updated' => 0, 'missing' => count($updates), 'cache' => 'lock_failed'];
    }

    rewind($fp);
    $raw = stream_get_contents($fp);
    $payload = json_decode($raw ?: '', true);
    if (!is_array($payload)) {
        flock($fp, LOCK_UN);
        fclose($fp);
        return ['updated' => 0, 'missing' => count($updates), 'cache' => 'invalid_json'];
    }

    $wrapped = isset($payload['itens']) && is_array($payload['itens']);
    $products = $wrapped ? $payload['itens'] : $payload;
    $updated = 0;
    $matched = [];

    foreach ($products as &$product) {
        if (!is_array($product)) {
            continue;
        }

        $productSku = strtoupper(trim((string)($product['sku'] ?? $product['codigo'] ?? '')));
        $productId = trim((string)($product['id'] ?? $product['olist_product_id'] ?? ''));

        foreach ($updates as $index => $change) {
            $sameSku = $change['sku'] !== '' && $productSku !== '' && $productSku === strtoupper($change['sku']);
            $sameId = $change['id'] !== '' && $productId !== '' && $productId === $change['id'];
            if (!$sameSku && !$sameId) {
                continue;
            }

            if ($change['price'] !== null) {
                $product['price'] = $change['price'];
                $product['preco'] = $change['price'];
                $product['preco_venda'] = $change['price'];
                $product['precos'] = is_array($product['precos'] ?? null) ? $product['precos'] : [];
                $product['precos']['preco'] = $change['price'];
            }

            if ($change['stock'] !== null) {
                $product['stock'] = $change['stock'];
                $product['estoque_disponivel'] = $change['stock'];
                $product['estoque'] = is_array($product['estoque'] ?? null) ? $product['estoque'] : [];
                $product['estoque']['quantidade'] = $change['stock'];
            }

            $product['synced_at'] = date('c');
            $product['_webhook_synced_at'] = date('c');
            $matched[$index] = true;
            $updated++;
            break;
        }
    }
    unset($product);

    if ($wrapped) {
        $payload['itens'] = $products;
        $payload['timestamp'] = date('c');
        $payload['total'] = count($products);
    } else {
        $payload = $products;
    }

    $encoded = json_encode(
        $payload,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT
    );

    if ($encoded === false) {
        flock($fp, LOCK_UN);
        fclose($fp);
        return ['updated' => 0, 'missing' => count($updates), 'cache' => 'encode_failed'];
    }

    rewind($fp);
    ftruncate($fp, 0);
    fwrite($fp, $encoded);
    fflush($fp);
    flock($fp, LOCK_UN);
    fclose($fp);

    return [
        'updated' => $updated,
        'missing' => max(0, count($updates) - count($matched)),
        'cache' => 'ok',
    ];
}

function queue_full_sync(): array
{
    global $root;

    $daemon = $root . '/daemon-sync-products.py';
    if (!is_file($daemon)) {
        return ['queued' => false, 'reason' => 'daemon_not_found'];
    }

    $log = $root . '/logs/sync-products.log';
    $command = 'cd ' . escapeshellarg($root)
        . ' && nohup /usr/bin/python3 ' . escapeshellarg($daemon)
        . ' >> ' . escapeshellarg($log) . ' 2>&1 < /dev/null &';

    @exec($command, $output, $code);
    return ['queued' => $code === 0, 'exit_code' => $code];
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'GET' && (string)($_GET['health'] ?? '') === '1') {
    respond(200, [
        'ok' => true,
        'endpoint' => 'olist-webhook-receiver',
        'cache_exists' => is_file($cacheFile),
        'cache_writable' => is_file($cacheFile) ? is_writable($cacheFile) : is_writable(dirname($cacheFile)),
        'timestamp' => date('c'),
    ]);
}

if ($method !== 'POST') {
    respond(405, ['ok' => false, 'error' => 'method_not_allowed', 'allowed' => ['POST']]);
}

$raw = (string)file_get_contents('php://input');
$data = json_decode($raw, true);
if (!is_array($data)) {
    $data = $_POST;
}

$event = strtolower(trim((string)(
    $_GET['event']
    ?? $_POST['event']
    ?? first_value($data, ['event', 'tipo', 'type', 'evento'])
    ?? 'product'
)));

$aliases = [
    'price_update' => 'price',
    'product_price' => 'price',
    'stock_update' => 'stock',
    'produto' => 'product',
    'pedido' => 'order',
    'orders' => 'order',
];
$event = $aliases[$event] ?? $event;

if (!in_array($event, ['price', 'stock', 'product', 'order'], true)) {
    respond(400, ['ok' => false, 'error' => 'unknown_event', 'event' => $event]);
}

log_event('Webhook recebido: event=' . $event . ' method=' . $method . ' bytes=' . strlen($raw));

if ($event === 'order') {
    $orderId = trim((string)(first_value($data, ['id', 'pedido_id', 'order_id', 'pedido.id', 'order.id']) ?? ''));
    if ($orderId === '') {
        respond(400, ['ok' => false, 'error' => 'missing_order_id']);
    }
    log_event('Pedido recebido: id=' . $orderId);
    respond(200, ['ok' => true, 'event' => 'order', 'order_id' => $orderId]);
}

$items = collect_items($data);
$updates = [];
$invalid = 0;

foreach ($items as $item) {
    $normalized = normalize_item($item);

    if ($normalized['sku'] === '' && $normalized['id'] === '') {
        $invalid++;
        continue;
    }
    if ($event === 'price' && $normalized['price'] === null) {
        $invalid++;
        continue;
    }
    if ($event === 'stock' && $normalized['stock'] === null) {
        $invalid++;
        continue;
    }
    if ($event === 'product' && $normalized['price'] === null && $normalized['stock'] === null) {
        $invalid++;
        continue;
    }

    $updates[] = $normalized;
}

if ($updates === []) {
    log_event('ERRO: nenhum item valido. recebidos=' . count($items) . ' invalidos=' . $invalid);
    respond(400, [
        'ok' => false,
        'error' => 'no_valid_items',
        'received' => count($items),
        'invalid' => $invalid,
    ]);
}

$result = update_cache($updates);
$sync = ['queued' => false, 'reason' => 'not_required'];

if ($result['cache'] !== 'ok' || $result['missing'] > 0) {
    $sync = queue_full_sync();
}

log_event(
    'Processado: recebidos=' . count($items)
    . ' validos=' . count($updates)
    . ' atualizados=' . $result['updated']
    . ' ausentes=' . $result['missing']
    . ' cache=' . $result['cache']
);

respond(200, [
    'ok' => true,
    'event' => $event,
    'received' => count($items),
    'valid' => count($updates),
    'updated' => $result['updated'],
    'missing' => $result['missing'],
    'invalid' => $invalid,
    'cache' => $result['cache'],
    'full_sync' => $sync,
]);

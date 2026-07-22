<?php
/**
 * Webhook receiver para notificações da Olist/Tiny
 * Processa: product, stock, price, orders
 * GET/POST /api/olist/webhook-receiver.php?event=price
 */
declare(strict_types=1);

header('Content-Type: application/json; charset=UTF-8');

$log_dir = __DIR__ . '/../../logs';
if (!is_dir($log_dir)) {
    mkdir($log_dir, 0755, true);
}

$event = $_GET['event'] ?? $_POST['event'] ?? '';
$log_file = $log_dir . '/olist-webhook-receiver.log';

function log_event($message) {
    global $log_file;
    $timestamp = date('Y-m-d H:i:s');
    file_put_contents($log_file, "[{$timestamp}] {$message}\n", FILE_APPEND);
}

// Log inicial
log_event("Webhook recebido: event={$event} method=" . $_SERVER['REQUEST_METHOD']);

if (!$event) {
    http_response_code(400);
    log_event("ERRO: Parametro 'event' nao fornecido");
    echo json_encode(['error' => 'Missing event parameter']);
    exit;
}

// Ler payload
$input = file_get_contents('php://input');
$data = json_decode($input, true);

if (!$data) {
    $data = $_POST;
}

log_event("Event: {$event} | Payload size: " . strlen($input) . " bytes");

// Processar por tipo de evento
switch ($event) {
    case 'price':
        handle_price_update($data, $log_file);
        break;

    case 'stock':
        handle_stock_update($data, $log_file);
        break;

    case 'product':
        handle_product_update($data, $log_file);
        break;

    case 'order':
        handle_order_update($data, $log_file);
        break;

    default:
        http_response_code(400);
        log_event("Evento desconhecido: {$event}");
        echo json_encode(['error' => 'Unknown event type']);
        exit;
}

function handle_price_update($data, $log_file) {
    log_event("PRICE UPDATE");

    if (!$data) {
        http_response_code(400);
        log_event("  ❌ Payload vazio");
        echo json_encode(['error' => 'Empty payload']);
        return;
    }

    // Extrair SKU e novo preço
    $sku = $data['sku'] ?? $data['produto_sku'] ?? $data['codigo'] ?? '';
    $price = $data['preco'] ?? $data['price'] ?? $data['preco_venda'] ?? null;

    if (!$sku || $price === null) {
        http_response_code(400);
        log_event("  ❌ SKU ou preco ausente");
        echo json_encode(['error' => 'Missing SKU or price']);
        return;
    }

    log_event("  SKU: {$sku} | Novo preço: R$ {$price}");

    // Invalidar cache
    $cache_file = __DIR__ . '/../../storage/products-cache-ativos.json';
    if (file_exists($cache_file)) {
        $backup = $cache_file . '.webhook-' . time();
        if (rename($cache_file, $backup)) {
            log_event("  ✓ Cache invalidado (backup: " . basename($backup) . ")");
        }
    }

    // Disparar sincronização em background
    $daemon = __DIR__ . '/../../daemon-sync-products.py';
    if (is_file($daemon)) {
        $cmd = "cd " . escapeshellarg(__DIR__ . '/../../') . " && /usr/bin/python3 daemon-sync-products.py >> " . escapeshellarg(__DIR__ . '/../../logs/sync-products.log') . " 2>&1 &";
        exec($cmd, $output, $code);
        log_event("  ✓ Sincronização disparada (código: {$code})");
    }

    http_response_code(200);
    echo json_encode(['status' => 'received', 'sku' => $sku, 'price' => $price, 'action' => 'sync queued']);
    log_event("  ✅ Processado com sucesso");
}

function handle_stock_update($data, $log_file) {
    log_event("STOCK UPDATE");

    $sku = $data['sku'] ?? $data['produto_sku'] ?? $data['codigo'] ?? '';
    $quantity = $data['estoque'] ?? $data['quantity'] ?? $data['estoque_disponivel'] ?? 0;

    if (!$sku) {
        http_response_code(400);
        log_event("  ❌ SKU ausente");
        echo json_encode(['error' => 'Missing SKU']);
        return;
    }

    log_event("  SKU: {$sku} | Novo estoque: {$quantity}");

    // Invalidar cache
    $cache_file = __DIR__ . '/../../storage/products-cache-ativos.json';
    if (file_exists($cache_file)) {
        rename($cache_file, $cache_file . '.webhook-' . time());
        log_event("  ✓ Cache invalidado");
    }

    http_response_code(200);
    echo json_encode(['status' => 'received', 'sku' => $sku, 'quantity' => $quantity]);
    log_event("  ✅ Processado com sucesso");
}

function handle_product_update($data, $log_file) {
    log_event("PRODUCT UPDATE");

    $sku = $data['sku'] ?? $data['produto_sku'] ?? $data['codigo'] ?? '';
    if (!$sku) {
        http_response_code(400);
        echo json_encode(['error' => 'Missing SKU']);
        return;
    }

    log_event("  SKU: {$sku}");

    // Invalidar cache
    $cache_file = __DIR__ . '/../../storage/products-cache-ativos.json';
    if (file_exists($cache_file)) {
        rename($cache_file, $cache_file . '.webhook-' . time());
        log_event("  ✓ Cache invalidado");
    }

    http_response_code(200);
    echo json_encode(['status' => 'received', 'sku' => $sku]);
    log_event("  ✅ Processado com sucesso");
}

function handle_order_update($data, $log_file) {
    log_event("ORDER UPDATE");

    $order_id = $data['id'] ?? $data['pedido_id'] ?? $data['order_id'] ?? '';
    if (!$order_id) {
        http_response_code(400);
        echo json_encode(['error' => 'Missing order ID']);
        return;
    }

    log_event("  Order ID: {$order_id}");

    http_response_code(200);
    echo json_encode(['status' => 'received', 'order_id' => $order_id]);
    log_event("  ✅ Processado com sucesso");
}
?>

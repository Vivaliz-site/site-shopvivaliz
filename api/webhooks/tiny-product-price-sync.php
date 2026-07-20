<?php
/**
 * Webhook para sincronizar preços de produtos do Tiny em tempo real
 * POST /api/webhooks/tiny-product-price-sync.php
 */
declare(strict_types=1);

header('Content-Type: application/json; charset=UTF-8');

// Log inicial
$log_file = __DIR__ . '/../../logs/tiny-webhook-price.log';
$timestamp = date('Y-m-d H:i:s');

function log_event($message) {
    global $log_file, $timestamp;
    file_put_contents($log_file, "[{$timestamp}] {$message}\n", FILE_APPEND);
}

// Validar token (se configurado no Tiny)
$headers = getallheaders();
$bearer = $headers['Authorization'] ?? $headers['authorization'] ?? '';
if ($bearer && str_starts_with($bearer, 'Bearer ')) {
    $token = substr($bearer, 7);
    // Você pode validar o token do Tiny aqui
    log_event("Token recebido: " . substr($token, 0, 10) . "...");
}

// Ler payload
$input = file_get_contents('php://input');
log_event("Webhook recebido, tamanho: " . strlen($input) . " bytes");

if (!$input) {
    http_response_code(400);
    echo json_encode(['error' => 'Empty payload']);
    exit;
}

$data = json_decode($input, true);
if (!$data) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid JSON']);
    log_event("Erro: JSON inválido");
    exit;
}

log_event("Tipo: " . ($data['tipo'] ?? $data['event'] ?? 'unknown'));

// Validar tipo de evento (product.updated, produto.atualizado, etc)
$event = $data['tipo'] ?? $data['event'] ?? '';
if (!in_array($event, ['produto.atualizado', 'product.updated', 'PRODUTO_ATUALIZADO'], true)) {
    // Mesmo que não seja específico de preço, processa se for update
    if (!str_contains($event, 'atualiz') && !str_contains($event, 'update')) {
        http_response_code(200);
        echo json_encode(['status' => 'ignored', 'reason' => 'Not a product update event']);
        exit;
    }
}

// Extrair informações do produto
$product = $data['produto'] ?? $data['product'] ?? $data;
$sku = $product['sku'] ?? $product['codigo'] ?? '';

if (!$sku) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing SKU']);
    log_event("Erro: SKU não encontrado");
    exit;
}

log_event("Processando produto SKU: {$sku}");

// Verificar se é mudança de preço
$price = $product['precos']['preco'] ?? $product['preco'] ?? $product['price'] ?? null;
if ($price !== null) {
    log_event("Novo preço para {$sku}: R$ {$price}");

    // Invalidar cache para forçar sincronização
    $cache_file = __DIR__ . '/../../storage/products-cache-ativos.json';
    if (file_exists($cache_file)) {
        // Renomear para versão antiga (será regenerada na próxima sincronização)
        $backup = $cache_file . '.webhook-backup-' . time();
        rename($cache_file, $backup);
        log_event("Cache invalidado, backup: {$backup}");
    }

    // Disparar sincronização via background job
    $cmd = "cd " . escapeshellarg(__DIR__ . '/../../') . " && /usr/bin/python3 daemon-sync-products.py >> " . escapeshellarg(__DIR__ . '/../../logs/sync-products.log') . " 2>&1 &";
    exec($cmd, $output, $return_code);
    log_event("Sincronização disparada, código: {$return_code}");
}

// Responder com sucesso
http_response_code(200);
echo json_encode([
    'status' => 'received',
    'sku' => $sku,
    'timestamp' => $timestamp,
    'action' => 'sync queued'
]);
log_event("Webhook processado com sucesso");
?>

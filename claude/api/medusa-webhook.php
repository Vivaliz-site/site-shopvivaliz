<?php
/**
 * EHA Webhook Listener for Medusa Events
 * Recebe e processa eventos do Medusa Commerce
 *
 * Events:
 * - product.created: Novo produto criado
 * - product.updated: Produto atualizado
 * - order.created: Novo pedido
 */

// Adicionar headers de segurança
header('Content-Type: application/json');
header('X-Content-Type-Options: nosniff');

// Variáveis de ambiente
$secret = getenv('EHA_WEBHOOK_SECRET') ?: ($_ENV['EHA_WEBHOOK_SECRET'] ?? 'test_eha_webhook_secret');
$logDir = __DIR__ . '/../logs';

// Criar diretório de logs se não existir
if (!is_dir($logDir)) {
    mkdir($logDir, 0755, true);
}

// 1. Receber payload
$payload = file_get_contents('php://input');
$event = json_decode($payload, true);

// 2. Validar assinatura HMAC
$signature = $_SERVER['HTTP_X_MEDUSA_SIGNATURE'] ?? '';
$expected = hash_hmac('sha256', $payload, $secret);

// Log do evento recebido
$logEntry = [
    'timestamp' => date('Y-m-d H:i:s'),
    'event_type' => $event['type'] ?? 'unknown',
    'event_id' => $event['id'] ?? null,
    'data_id' => $event['data']['id'] ?? null,
    'signature_valid' => hash_equals($expected, $signature),
    'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown'
];

// Se assinatura inválida, retornar 401
if (!hash_equals($expected, $signature)) {
    $logEntry['status'] = 'UNAUTHORIZED';
    $logEntry['error'] = 'Invalid signature';
    logWebhookEvent($logEntry, $logDir);

    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

// 3. Processar por tipo de evento
$eventType = $event['type'] ?? 'unknown';
$eventData = $event['data'] ?? [];

try {
    switch ($eventType) {
        case 'product.created':
            handleProductCreated($eventData);
            $logEntry['status'] = 'PROCESSED';
            $logEntry['action'] = 'Product optimization initiated';
            break;

        case 'product.updated':
            handleProductUpdated($eventData);
            $logEntry['status'] = 'PROCESSED';
            $logEntry['action'] = 'Product updated and synced';
            break;

        case 'order.created':
        case 'order.placed':
            handleOrderCreated($eventData);
            $logEntry['status'] = 'PROCESSED';
            $logEntry['action'] = 'Order processing initiated';
            break;

        default:
            $logEntry['status'] = 'IGNORED';
            $logEntry['action'] = 'Unknown event type';
            break;
    }
} catch (Exception $e) {
    $logEntry['status'] = 'ERROR';
    $logEntry['error'] = $e->getMessage();
}

// 4. Log do evento
logWebhookEvent($logEntry, $logDir);

// 5. Resposta
http_response_code(200);
echo json_encode(['ok' => true, 'event_id' => $event['id'] ?? null]);

// ==================== HANDLERS ====================

/**
 * Handle product.created event
 * Dispara otimizações autônomas
 */
function handleProductCreated($product) {
    // Aqui virão as integrações com EHA:
    // 1. Otimizar descrição com IA
    // 2. Gerar imagens com IA
    // 3. Sincronizar com Shopee
    // 4. Sincronizar com Amazon
    // 5. Sincronizar com Olist

    // Por enquanto, apenas log
    error_log('[EHA] Produto criado: ' . ($product['id'] ?? 'unknown'));
}

/**
 * Handle product.updated event
 * Sincroniza atualizações
 */
function handleProductUpdated($product) {
    // Atualizar em marketplaces
    // Validar mudanças de preço
    // Atualizar estoque

    error_log('[EHA] Produto atualizado: ' . ($product['id'] ?? 'unknown'));
}

/**
 * Handle order.created event
 * Processa novo pedido
 */
function handleOrderCreated($order) {
    // Notificar vendedor
    // Sincronizar com marketplace
    // Gerar label de envio
    // Atualizar estoque

    error_log('[EHA] Pedido criado: ' . ($order['id'] ?? 'unknown'));
}

/**
 * Log de eventos em JSON
 */
function logWebhookEvent($entry, $logDir) {
    $logFile = $logDir . '/webhook-events.log';
    $line = json_encode($entry) . PHP_EOL;
    file_put_contents($logFile, $line, FILE_APPEND);
}
?>

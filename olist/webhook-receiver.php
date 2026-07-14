<?php
/**
 * Webhook Receiver - Olist envia notificações de mudanças
 * POST https://dev.shopvivaliz.com.br/olist/webhook-receiver.php
 *
 * Gatilhos:
 * - Produto criado
 * - Produto atualizado
 * - Preço alterado
 * - Estoque alterado
 */

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

$webhook_log = dirname(__DIR__) . '/logs/webhook.log';
@mkdir(dirname($webhook_log), 0755, true);

// Registrar requisição
$request_body = file_get_contents('php://input');
$timestamp = date('Y-m-d H:i:s');
$log_entry = "[$timestamp] " . $_SERVER['REQUEST_METHOD'] . " " . json_encode(json_decode($request_body, true)) . "\n";
@file_put_contents($webhook_log, $log_entry, FILE_APPEND);

// Validar método
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['erro' => 'Apenas POST permitido']);
    exit;
}

// Parse JSON
$data = json_decode($request_body, true);

if (!$data) {
    http_response_code(400);
    echo json_encode(['erro' => 'JSON inválido']);
    exit;
}

// ============================================================
// Processar eventos
// ============================================================

$event_type = $data['event'] ?? $data['tipo'] ?? $data['type'] ?? null;
$product_id = $data['produto_id'] ?? $data['product_id'] ?? $data['id'] ?? null;

// Eventos que interessam
$sync_events = ['produto.criado', 'produto.atualizado', 'produto.alterado', 'preco.alterado', 'estoque.alterado'];

if (in_array($event_type, $sync_events) || strpos($event_type, 'produto') !== false) {
    // Disparar sincronização
    exec('php ' . dirname(__DIR__) . '/olist/sync-on-webhook.php > /dev/null 2>&1 &');

    http_response_code(200);
    echo json_encode([
        'sucesso' => true,
        'mensagem' => 'Webhook recebido e sincronização iniciada',
        'event' => $event_type,
        'product_id' => $product_id,
    ]);
} else {
    http_response_code(200);
    echo json_encode([
        'sucesso' => true,
        'mensagem' => 'Webhook ignorado (evento não monitorado)',
        'event' => $event_type,
    ]);
}
?>

<?php
/**
 * Olist Webhook Receiver - Bridge Olist/Tiny ERP -> ShopVivaliz
 *
 * Recebe notificações de mudança de produto/estoque do Olist e enfileira
 * uma sincronização (OlistSync) para atualizar o catálogo Medusa.
 *
 * Cadastre esta URL no painel do Olist/Tiny (Configurações > Integrações >
 * Webhooks): https://SEU_DOMINIO/claude/api/olist/webhook.php
 */

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$logs_dir = __DIR__ . '/../../../storage/logs';
if (!is_dir($logs_dir)) {
    mkdir($logs_dir, 0755, true);
}

function olist_webhook_log(string $message, array $context = []): void
{
    global $logs_dir;
    $entry = [
        'timestamp' => date('c'),
        'message' => $message,
        'context' => $context,
    ];
    file_put_contents(
        $logs_dir . '/olist-webhook.log',
        json_encode($entry, JSON_UNESCAPED_UNICODE) . "\n",
        FILE_APPEND | LOCK_EX
    );
}

$raw_body = file_get_contents('php://input');
$data = json_decode($raw_body, true);

if (!$data) {
    http_response_code(400);
    echo json_encode(['error' => 'Payload inválido']);
    exit;
}

olist_webhook_log('Evento Olist recebido', $data);

// Dispara a sincronização OlistSync -> Medusa em segundo plano (best-effort,
// não bloqueia a resposta ao Olist).
require_once __DIR__ . '/../sync-olist-products.php';

try {
    $sync = new OlistSync();
    $result = $sync->run();
    olist_webhook_log('OlistSync executado a partir do webhook', ['ok' => $result['ok']]);
} catch (\Throwable $e) {
    olist_webhook_log('OlistSync falhou a partir do webhook', ['error' => $e->getMessage()]);
}

http_response_code(200);
echo json_encode(['ok' => true]);

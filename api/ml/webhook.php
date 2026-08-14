<?php
declare(strict_types=1);
require_once __DIR__ . '/client.php';
require_once __DIR__ . '/order-sync.php';

header_remove('X-Powered-By');
header('Content-Type: application/json; charset=utf-8');

$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

if ($method !== 'POST') {
    echo json_encode(['ok' => true, 'message' => 'Webhook ML ativo. Use POST para notificações.']);
    exit;
}

$raw  = file_get_contents('php://input') ?: '';
$body = json_decode($raw, true) ?? [];

$entry = [
    'received_at'    => gmdate('c'),
    'topic'          => trim($_GET['topic']          ?? ($body['topic'] ?? '')),
    'resource'       => trim($_GET['resource']       ?? ($body['resource'] ?? '')),
    'user_id'        => trim($_GET['user_id']        ?? (string)($body['user_id'] ?? '')),
    'application_id' => trim($_GET['application_id'] ?? (string)($body['application_id'] ?? '')),
    'body'           => $body,
];

$logDir = ml_root() . '/logs';
if (!is_dir($logDir)) @mkdir($logDir, 0755, true);
$line = json_encode($entry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
file_put_contents($logDir . '/ml-webhooks.log', $line, FILE_APPEND | LOCK_EX);

// Reage a notificacoes de pedido, gravando no mesmo formato usado pelo
// checkout do site (storage/orders/*.json), sem derrubar o webhook em
// caso de erro -- ML reduz/desativa notificacoes apos varias respostas
// nao-2xx, entao sempre respondemos 200 e so logamos falhas internas.
// SEGURANCA (SSRF): $resource vem do corpo/query da requisicao e era
// concatenado direto em 'https://api.mercadolibre.com' . $resource dentro de
// ml_sync_order_from_webhook(). Um valor como '/../..%2f' ou uma barra dupla
// permitiria redirecionar a chamada autenticada para outro caminho -- ou
// outro host -- levando junto o token do Mercado Livre. O ML sempre envia
// resource no formato /orders/{id}; qualquer outra coisa e descartada.
$resourceIsSafe = (bool)preg_match('#^/orders/\d+$#', $entry['resource']);
if ($entry['topic'] === 'orders_v2' && $entry['resource'] !== '' && !$resourceIsSafe) {
    file_put_contents(
        $logDir . '/ml-webhook-errors.log',
        json_encode(['at' => gmdate('c'), 'resource' => $entry['resource'], 'error' => 'resource_rejeitado_formato_invalido'], JSON_UNESCAPED_UNICODE) . "\n",
        FILE_APPEND | LOCK_EX
    );
}

if ($entry['topic'] === 'orders_v2' && $resourceIsSafe) {
    try {
        ml_sync_order_from_webhook($entry['resource']);
    } catch (Throwable $e) {
        file_put_contents(
            $logDir . '/ml-webhook-errors.log',
            json_encode(['at' => gmdate('c'), 'resource' => $entry['resource'], 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE) . "\n",
            FILE_APPEND | LOCK_EX
        );
    }
}

echo json_encode(['ok' => true]);

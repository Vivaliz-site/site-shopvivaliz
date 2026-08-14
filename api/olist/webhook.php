<?php
declare(strict_types=1);

header_remove('X-Powered-By');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

// SEGURANCA: este endpoint nao e consumido por navegador -- e um webhook
// servidor-a-servidor. O 'Access-Control-Allow-Origin: *' anterior apenas
// permitia que qualquer site disparasse a rota a partir do navegador da
// vitima, sem nenhum beneficio.

require_once dirname(__DIR__, 2) . '/includes/webhook-secret.php';

$raw = file_get_contents('php://input') ?: '';
$eventId = 'olist:' . md5($raw !== '' ? $raw : json_encode($_REQUEST));

// SEGURANCA: o processador executa UPDATE e DELETE em `products` a partir do
// corpo da requisicao. Sem autenticacao, qualquer POST anonimo podia zerar
// precos ou apagar produtos do catalogo. A Olist/Tiny nao assina webhooks,
// entao usamos segredo compartilhado na URL configurada no painel.
$decoded = json_decode($raw, true);
$eventType = is_array($decoded)
    ? (string)($decoded['event'] ?? $decoded['type'] ?? '')
    : '';
$isDestructive = $eventType === 'product.deleted';

if (!sv_webhook_secret_gate('olist', $isDestructive)) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'unauthorized'], JSON_UNESCAPED_UNICODE);
    exit;
}

// Processar webhook antes de responder
require_once __DIR__ . '/webhook-processor.php';

echo json_encode([
    'ok' => true,
    'provider' => 'olist',
    'event_type' => 'erp_event',
    'event_id' => $eventId,
    'method' => $_SERVER['REQUEST_METHOD'] ?? 'GET',
    'received_at' => date('c'),
    'message' => 'Webhook recebido pelo ShopVivaliz.',
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);

<?php

declare(strict_types=1);

header_remove('X-Powered-By');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

require_once dirname(__DIR__) . '/includes/mercadopago-gateway.php';
require_once dirname(__DIR__) . '/includes/tiny-order-push.php';
require_once dirname(__DIR__) . '/api/emails/send-order-notification.php';
require_once dirname(__DIR__) . '/includes/ml-event-tracker.php';
require_once dirname(__DIR__) . '/includes/payment-notification-idempotency.php';
require_once dirname(__DIR__) . '/includes/webhook-queue.php';

function svmp_webhook_response(int $status, string $result): never
{
    http_response_code($status);
    echo json_encode(['ok' => $status >= 200 && $status < 300, 'result' => $result]);
    exit;
}

// Rodada 9 (2026-08-19): svmp_webhook_extract_boleto() e
// svmp_webhook_send_boleto_email() foram movidas pra
// includes/mercadopago-gateway.php -- o worker de fila
// (includes/webhook-job-dispatcher.php) precisa delas tambem e nao pode dar
// require neste arquivo (executaria o tratamento de requisicao HTTP abaixo
// fora de contexto). Ver docs/AGENTS.md, entrada da Rodada 9.
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    svmp_webhook_response(405, 'method_not_allowed');
}

$raw = (string)file_get_contents('php://input');
if (strlen($raw) > 50000) {
    svmp_webhook_response(413, 'payload_too_large');
}
$body = json_decode($raw, true);
$body = is_array($body) ? $body : [];

$dataId = trim((string)($_GET['data.id'] ?? $_GET['data_id'] ?? $body['data']['id'] ?? $body['id'] ?? ''));
$topic = strtolower(trim((string)($_GET['type'] ?? $_GET['topic'] ?? $body['type'] ?? $body['action'] ?? '')));

// O topico "merchant_order" (ex: "topic_merchant_order_wh") manda o ID do
// merchant order, nao de um pagamento -- mas o codigo abaixo so sabe tratar
// 'order' (Order API v2) ou pagamento avulso, entao tratava esse ID como se
// fosse um payment ID por engano. Isso gerava um GET /v1/payments/{merchant_
// order_id} que ou falha ou (pior) acerta em outro recurso, sobrescrevendo o
// pedido com dados errados/incompletos (confirmado ao vivo: pedido real teve
// nome do cliente e forma de pagamento trocados por isso). O topico
// "payment" ja chega em paralelo pra cada merchant_order e carrega tudo que
// precisamos -- ignoramos merchant_order por completo em vez de tentar
// tratar direito (a Order API v2, unico jeito de ler merchant_order certo,
// exige outro endpoint that nao usamos aqui).
if (str_contains($topic, 'merchant_order')) {
    svmp_webhook_response(200, 'ignored_topic');
}
$signature = trim((string)($_SERVER['HTTP_X_SIGNATURE'] ?? ''));
if ($signature === '' && function_exists('getallheaders')) {
    foreach (getallheaders() as $headerName => $headerValue) {
        if (strcasecmp($headerName, 'X-Signature') === 0) {
            $signature = trim((string)$headerValue);
            break;
        }
    }
}
$requestId = trim((string)($_SERVER['HTTP_X_REQUEST_ID'] ?? ''));
if ($requestId === '' && function_exists('getallheaders')) {
    foreach (getallheaders() as $headerName => $headerValue) {
        if (strcasecmp($headerName, 'X-Request-Id') === 0) {
            $requestId = trim((string)$headerValue);
            break;
        }
    }
}
$webhookSecret = svmp_env('MERCADOPAGO_WEBHOOK_SECRET');
$accessToken = svmp_env('MERCADOPAGO_ACCESS_TOKEN');

if ($webhookSecret === '' || $accessToken === '') {
    error_log('[MercadoPago] webhook unavailable: missing runtime configuration');
    svmp_webhook_response(503, 'gateway_unconfigured');
}

// SEGURANCA: a validacao de assinatura precisa acontecer ANTES de qualquer
// efeito colateral. Antes, o enfileiramento e o svmp_webhook_response(200,
// 'queued') -- que chama exit() -- vinham primeiro, tornando a verificacao de
// assinatura logo abaixo codigo inalcancavel. Na pratica o webhook do Mercado
// Pago aceitava qualquer POST nao autenticado e criava um job interno.
if (!svmp_validate_webhook_signature($signature, $requestId, $dataId, $webhookSecret)) {
    error_log('[MercadoPago] webhook rejected: invalid signature request=' . substr($requestId, 0, 80) . ' sig_len=' . strlen($signature) . ' data_id=' . substr($dataId, 0, 80));
    svmp_webhook_response(401, 'invalid_signature');
}

// O marcador auth_validated replica a defesa ja usada pelo InfinitePay: o
// worker recusa jobs que nao passaram pela borda autenticada.
$queuedId = sv_webhook_enqueue('mercadopago', [
    'raw' => $raw,
    'data_id' => $dataId,
    'topic' => $topic,
    'signature' => $signature,
    'request_id' => $requestId,
    'auth_validated' => true,
    'received_at' => date(DATE_ATOM),
]);
error_log('[MercadoPago] webhook queued id=' . $queuedId . ' data=' . $dataId);
svmp_webhook_response(200, 'queued');

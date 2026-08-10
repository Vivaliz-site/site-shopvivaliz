<?php
declare(strict_types=1);

header_remove('X-Powered-By');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

require_once dirname(__DIR__) . '/includes/infinitepay-gateway.php';
require_once dirname(__DIR__) . '/includes/mercadopago-gateway.php';
require_once dirname(__DIR__) . '/includes/tiny-order-push.php';
require_once dirname(__DIR__) . '/api/emails/send-order-notification.php';
require_once dirname(__DIR__) . '/includes/ml-event-tracker.php';
require_once dirname(__DIR__) . '/includes/analytics-tracking.php';
require_once dirname(__DIR__) . '/includes/webhook-queue.php';

function svip_webhook_response(int $status, string $result): never
{
    http_response_code($status);
    echo json_encode(['ok' => $status >= 200 && $status < 300, 'result' => $result], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function svip_webhook_local_status(string $providerStatus): string
{
    return match (strtolower(trim($providerStatus))) {
        'paid', 'approved', 'succeeded', 'success', 'completed', 'confirmed' => 'payment_approved',
        'pending', 'waiting_payment', 'waiting', 'created', 'processing', 'in_process' => 'payment_pending',
        'cancelled', 'canceled', 'expired', 'voided' => 'payment_cancelled',
        'refunded' => 'payment_refunded',
        'charged_back', 'chargeback' => 'payment_chargeback',
        'rejected', 'failed', 'denied', 'error' => 'payment_failed',
        default => 'payment_pending',
    };
}

function svip_webhook_order_number(array $payload): string
{
    $candidates = [
        $payload['order_nsu'] ?? null,
        $payload['order']['order_nsu'] ?? null,
        $payload['data']['order_nsu'] ?? null,
        $payload['metadata']['order_nsu'] ?? null,
        $payload['reference'] ?? null,
        $payload['external_reference'] ?? null,
        $payload['order']['external_reference'] ?? null,
    ];
    foreach ($candidates as $candidate) {
        $value = trim((string)$candidate);
        if (svmp_order_number_is_valid($value)) {
            return $value;
        }
    }
    return '';
}

function svip_webhook_status(array $payload): string
{
    $candidates = [
        $payload['status'] ?? null,
        $payload['payment_status'] ?? null,
        $payload['payment']['status'] ?? null,
        $payload['order']['status'] ?? null,
        $payload['data']['status'] ?? null,
        $payload['event'] ?? null,
        $payload['type'] ?? null,
    ];
    foreach ($candidates as $candidate) {
        $value = trim((string)$candidate);
        if ($value !== '') {
            return $value;
        }
    }
    return 'pending';
}

/**
 * Le um header de request de forma confiavel.
 *
 * Apache/PHP-FPM sem CGIPassAuth nao repassa Authorization (e as vezes outros
 * headers customizados) para $_SERVER — comportamento ja confirmado ao vivo
 * neste servidor em api/webhooks/order-status-update.php, onde a ausencia
 * deste fallback fazia o endpoint rejeitar TODA chamada real com 401.
 */
function svip_request_header(string $name): string
{
    $serverKey = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
    $value = trim((string)($_SERVER[$serverKey] ?? ''));
    if ($value !== '') {
        return $value;
    }
    if (function_exists('getallheaders')) {
        foreach (getallheaders() as $headerName => $headerValue) {
            if (strcasecmp($headerName, $name) === 0) {
                return trim((string)$headerValue);
            }
        }
    }
    return '';
}

/**
 * Le o segredo compartilhado enviado pela InfinitePay.
 * Aceita header (X-Webhook-Token / Authorization: Bearer) ou query string (?token=),
 * porque o painel da InfinitePay so permite configurar a URL de callback.
 */
function svip_webhook_provided_token(): string
{
    $header = svip_request_header('X-Webhook-Token');
    if ($header !== '') {
        return $header;
    }
    $auth = svip_request_header('Authorization');
    if (stripos($auth, 'Bearer ') === 0) {
        return trim(substr($auth, 7));
    }
    return trim((string)($_GET['token'] ?? ''));
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    svip_webhook_response(405, 'method_not_allowed');
}

// Sem esta verificacao qualquer um que descubra um numero de pedido poderia
// marca-lo como pago, disparando o push para o Tiny e o e-mail de confirmacao.
$webhookSecret = svip_env('INFINITEPAY_WEBHOOK_SECRET');
if ($webhookSecret === '') {
    error_log('[InfinitePay] webhook rejected: INFINITEPAY_WEBHOOK_SECRET nao configurado');
    svip_webhook_response(503, 'webhook_secret_not_configured');
}
if (!hash_equals($webhookSecret, svip_webhook_provided_token())) {
    error_log('[InfinitePay] webhook rejected: invalid token from ' . ($_SERVER['REMOTE_ADDR'] ?? '?'));
    svip_webhook_response(401, 'invalid_token');
}

$raw = (string)file_get_contents('php://input');
if ($raw === '' || strlen($raw) > 100000) {
    svip_webhook_response(400, 'invalid_payload');
}

$payload = json_decode($raw, true);
if (!is_array($payload)) {
    svip_webhook_response(400, 'invalid_json');
}

$queuedId = sv_webhook_enqueue('infinitepay', [
    'raw' => $raw,
    'payload' => $payload,
    'received_at' => date(DATE_ATOM),
    'request_id' => svip_request_header('X-Request-Id'),
    'auth_validated' => true,
], 45);
error_log('[InfinitePay] webhook queued id=' . $queuedId);
svip_webhook_response(200, 'queued');

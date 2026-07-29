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

$orderNumber = svip_webhook_order_number($payload);
if ($orderNumber === '') {
    error_log('[InfinitePay] webhook ignored: missing order_nsu');
    svip_webhook_response(200, 'missing_order_nsu');
}

$path = svmp_find_order_path($orderNumber);
if ($path === '') {
    error_log('[InfinitePay] webhook ignored: order not found ' . $orderNumber);
    svip_webhook_response(200, 'order_not_found');
}

$providerStatus = svip_webhook_status($payload);
$localStatus = svip_webhook_local_status($providerStatus);
$handle = fopen($path, 'r+');
if ($handle === false || !flock($handle, LOCK_EX)) {
    if (is_resource($handle)) {
        fclose($handle);
    }
    svip_webhook_response(503, 'order_lock_unavailable');
}

try {
    rewind($handle);
    $order = json_decode((string)stream_get_contents($handle), true);
    if (!is_array($order) || ($order['order_number'] ?? '') !== $orderNumber) {
        svip_webhook_response(200, 'order_invalid');
    }

    $order['status'] = $localStatus;
    $order['infinitepay'] = is_array($order['infinitepay'] ?? null) ? $order['infinitepay'] : [];
    $order['infinitepay']['status'] = $providerStatus;
    $order['infinitepay']['last_webhook_at'] = date(DATE_ATOM);
    $order['infinitepay']['payload'] = $payload;
    $order['infinitepay']['transaction_nsu'] = trim((string)($payload['transaction_nsu'] ?? $payload['payment']['transaction_nsu'] ?? ''));
    $order['infinitepay']['payment_id'] = trim((string)($payload['id'] ?? $payload['payment']['id'] ?? ''));

    if ($localStatus === 'payment_approved' && empty($order['tiny_order_id'])) {
        if (svtop_tiny_credentials_configured()) {
            try {
                $tinyOrderId = svtop_push_order_tiny($order);
                if ($tinyOrderId) {
                    $order['tiny_order_id'] = $tinyOrderId;
                    $order['tiny_push'] = 'ok';
                } else {
                    $order['tiny_push'] = 'token_unavailable';
                }
            } catch (Throwable $e) {
                $order['tiny_push'] = $e->getMessage();
                error_log('[InfinitePay] Tiny push error: order=' . $orderNumber . ' ' . $e->getMessage());
            }
        } else {
            $order['tiny_push'] = 'missing_credentials';
        }
    }

    $encoded = json_encode($order, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    rewind($handle);
    ftruncate($handle, 0);
    if (fwrite($handle, $encoded) === false || !fflush($handle)) {
        svip_webhook_response(500, 'order_write_failed');
    }
} finally {
    flock($handle, LOCK_UN);
    fclose($handle);
}

try {
    require_once dirname(__DIR__) . '/includes/pdo-database.php';
    require_once dirname(__DIR__) . '/includes/account-schema.php';
    sv_account_ensure_schema();

    $orderStatusMap = [
        'payment_approved' => 'pagamento_aprovado',
        'payment_pending' => 'aguardando_pagamento',
        'payment_refunded' => 'devolvido',
        'payment_chargeback' => 'devolvido',
        'payment_cancelled' => 'cancelado',
        'payment_failed' => 'cancelado',
    ];
    $mappedStatus = $orderStatusMap[$localStatus] ?? 'aguardando_pagamento';

    $pdo = sv_pdo();
    $stmt = $pdo->prepare(
        'UPDATE orders SET order_status = :status, olist_order_id = COALESCE(:olist_order_id, olist_order_id), updated_at = NOW()
         WHERE order_number = :order_number'
    );
    $stmt->execute([
        ':status' => $mappedStatus,
        ':olist_order_id' => $order['tiny_order_id'] ?? null,
        ':order_number' => $orderNumber,
    ]);
} catch (Throwable $e) {
    error_log('[InfinitePay] MySQL orders mirror failed: order=' . $orderNumber . ' ' . $e->getMessage());
}

if ($localStatus === 'payment_approved') {
    foreach (is_array($order['items'] ?? null) ? $order['items'] : [] as $item) {
        if (!is_array($item)) {
            continue;
        }
        $productId = (string)($item['olist_product_id'] ?? $item['sku'] ?? '');
        if ($productId === '') {
            continue;
        }
        svml_track_event('purchase', $productId, [
            'source' => 'infinitepay_webhook',
            'order_number' => $orderNumber,
            'payment_id' => (string)($order['infinitepay']['payment_id'] ?? ''),
        ]);
    }

    try {
        svem_send_order_email($order, 'payment_received');
    } catch (Throwable $e) {
        error_log('[InfinitePay] payment email failure: order=' . $orderNumber . ' ' . $e->getMessage());
    }
}

error_log('[InfinitePay] webhook processed: order=' . $orderNumber . ' status=' . $providerStatus);
svip_webhook_response(200, 'processed');

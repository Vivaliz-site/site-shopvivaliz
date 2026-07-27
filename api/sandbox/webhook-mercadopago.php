<?php

declare(strict_types=1);

header_remove('X-Powered-By');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

require_once dirname(__DIR__, 2) . '/includes/mercadopago-sandbox.php';

function svmp_sb_webhook_response(int $status, string $result): never
{
    http_response_code($status);
    echo json_encode(['ok' => $status >= 200 && $status < 300, 'result' => $result]);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    svmp_sb_webhook_response(405, 'method_not_allowed');
}

$raw = (string)file_get_contents('php://input');
$body = json_decode($raw, true);
$body = is_array($body) ? $body : [];

$dataId = trim((string)($_GET['data.id'] ?? $_GET['data_id'] ?? $body['data']['id'] ?? $body['id'] ?? ''));
$signature = trim((string)($_SERVER['HTTP_X_SIGNATURE'] ?? ''));
$requestId = trim((string)($_SERVER['HTTP_X_REQUEST_ID'] ?? ''));
$secret = svmp_sb_webhook_secret();
$accessToken = svmp_sb_access_token();

if ($secret === '' || $accessToken === '') {
    svmp_sb_webhook_response(503, 'sandbox_gateway_unconfigured');
}

if (!svmp_validate_webhook_signature($signature, $requestId, $dataId, $secret)) {
    svmp_sb_webhook_response(401, 'invalid_signature');
}

try {
    $payment = svmp_api_request('GET', '/v1/payments/' . rawurlencode($dataId), $accessToken);
    $orderNumber = trim((string)($payment['external_reference'] ?? ''));
    $path = svmp_sb_find_order_path($orderNumber);
    if ($path === '') {
        svmp_sb_webhook_response(200, 'order_not_found');
    }

    $status = svmp_local_status((string)($payment['status'] ?? 'pending'));
    $order = json_decode((string)file_get_contents($path), true);
    if (!is_array($order)) {
        svmp_sb_webhook_response(200, 'order_invalid');
    }

    $order['status'] = $status;
    $order['mercadopago'] = is_array($order['mercadopago'] ?? null) ? $order['mercadopago'] : [];
    $order['mercadopago']['payment_id'] = (string)($payment['id'] ?? '');
    $order['mercadopago']['status'] = (string)($payment['status'] ?? '');
    $order['mercadopago']['status_detail'] = (string)($payment['status_detail'] ?? '');
    $order['mercadopago']['payment_method_id'] = trim((string)($payment['payment_method_id'] ?? $payment['payment_method']['id'] ?? ''));
    $order['mercadopago']['payment_type_id'] = trim((string)($payment['payment_type_id'] ?? $payment['payment_type']['id'] ?? ''));
    $order['mercadopago']['transaction_amount'] = round((float)($payment['transaction_amount'] ?? $order['total'] ?? 0), 2);
    $order['mercadopago']['last_webhook_at'] = date(DATE_ATOM);
    $order['mercadopago']['last_webhook_topic'] = 'payment';

    $encoded = json_encode($order, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($encoded === false || file_put_contents($path, $encoded, LOCK_EX) === false) {
        svmp_sb_webhook_response(500, 'sandbox_order_write_failed');
    }

    svmp_sb_webhook_response(200, 'processed');
} catch (SvMercadoPagoApiException $e) {
    svmp_sb_webhook_response($e->httpStatus === 422 ? 200 : 502, $e->publicCode);
} catch (Throwable $e) {
    svmp_sb_webhook_response(500, 'internal_error');
}


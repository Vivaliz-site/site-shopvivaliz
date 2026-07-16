<?php

declare(strict_types=1);

header_remove('X-Powered-By');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

require_once dirname(__DIR__) . '/includes/mercadopago-gateway.php';
require_once dirname(__DIR__) . '/includes/tiny-order-push.php';

function svmp_webhook_response(int $status, string $result): never
{
    http_response_code($status);
    echo json_encode(['ok' => $status >= 200 && $status < 300, 'result' => $result]);
    exit;
}
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    svmp_webhook_response(405, 'method_not_allowed');
}

$raw = (string)file_get_contents('php://input');
if (strlen($raw) > 50000) {
    svmp_webhook_response(413, 'payload_too_large');
}
$body = json_decode($raw, true);
$body = is_array($body) ? $body : [];

$dataId = trim((string)($_GET['data.id'] ?? $_GET['data_id'] ?? $body['data']['id'] ?? ''));
$topic = strtolower(trim((string)($_GET['type'] ?? $body['type'] ?? '')));
$signature = trim((string)($_SERVER['HTTP_X_SIGNATURE'] ?? ''));
$requestId = trim((string)($_SERVER['HTTP_X_REQUEST_ID'] ?? ''));
$webhookSecret = svmp_env('MERCADOPAGO_WEBHOOK_SECRET');
$accessToken = svmp_env('MERCADOPAGO_ACCESS_TOKEN');

if ($webhookSecret === '' || $accessToken === '') {
    error_log('[MercadoPago] webhook unavailable: missing runtime configuration');
    svmp_webhook_response(503, 'gateway_unconfigured');
}
if (!svmp_validate_webhook_signature($signature, $requestId, $dataId, $webhookSecret)) {
    error_log('[MercadoPago] webhook rejected: invalid signature request=' . substr($requestId, 0, 80));
    svmp_webhook_response(401, 'invalid_signature');
}

try {
    $isOrder = $topic === 'order' || str_starts_with(strtoupper($dataId), 'ORD');
    $resource = svmp_api_request('GET', $isOrder ? '/v1/orders/' . rawurlencode($dataId) : '/v1/payments/' . rawurlencode($dataId), $accessToken);
    $externalReference = trim((string)($resource['external_reference'] ?? ''));
    if (!svmp_order_number_is_valid($externalReference)) {
        error_log('[MercadoPago] webhook ignored: external reference not managed resource=' . substr($dataId, 0, 80));
        svmp_webhook_response(200, 'not_managed');
    }

    $path = svmp_find_order_path($externalReference);
    if ($path === '') {
        error_log('[MercadoPago] webhook order not found: order=' . $externalReference);
        svmp_webhook_response(200, 'order_not_found');
    }

    $payment = $isOrder && is_array($resource['transactions']['payments'][0] ?? null)
        ? $resource['transactions']['payments'][0]
        : $resource;
    $providerStatus = (string)($payment['status'] ?? $resource['status'] ?? 'pending');
    $statusDetail = (string)($payment['status_detail'] ?? $resource['status_detail'] ?? '');
    $localStatus = svmp_local_status($providerStatus);

    $handle = fopen($path, 'r+');
    if ($handle === false || !flock($handle, LOCK_EX)) {
        if (is_resource($handle)) {
            fclose($handle);
        }
        svmp_webhook_response(503, 'order_lock_unavailable');
    }

    try {
        rewind($handle);
        $order = json_decode((string)stream_get_contents($handle), true);
        if (!is_array($order) || ($order['order_number'] ?? '') !== $externalReference) {
            svmp_webhook_response(200, 'order_invalid');
        }

        $currentStatus = (string)($order['status'] ?? '');
        $terminalExceptions = ['payment_refunded', 'payment_chargeback'];
        if ($currentStatus !== 'payment_approved' || in_array($localStatus, $terminalExceptions, true)) {
            $order['status'] = $localStatus;
        }
        $order['mercadopago'] = is_array($order['mercadopago'] ?? null) ? $order['mercadopago'] : [];
        $order['mercadopago']['order_id'] = $isOrder ? (string)($resource['id'] ?? $dataId) : (string)($order['mercadopago']['order_id'] ?? '');
        $order['mercadopago']['payment_id'] = (string)($payment['id'] ?? $dataId);
        $order['mercadopago']['status'] = $providerStatus;
        $order['mercadopago']['status_detail'] = $statusDetail;
        $order['mercadopago']['last_webhook_at'] = date(DATE_ATOM);
        $order['mercadopago']['last_webhook_topic'] = $isOrder ? 'order' : 'payment';

        $encoded = json_encode($order, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        rewind($handle);
        ftruncate($handle, 0);
        if (fwrite($handle, $encoded) === false || !fflush($handle)) {
            svmp_webhook_response(500, 'order_write_failed');
        }
    } finally {
        flock($handle, LOCK_UN);
        fclose($handle);
    }

    error_log('[MercadoPago] webhook processed: order=' . $externalReference . ' resource=' . $dataId . ' status=' . $providerStatus);

    // Enviar email de confirmação em background (se pagamento foi aprovado)
    if ($localStatus === 'payment_approved') {
        $postProcCmd = 'php ' . escapeshellarg(__DIR__ . '/webhook-post-processor.php') . ' ' .
                       escapeshellarg($externalReference) . ' ' .
                       escapeshellarg($path);

        // Executar em background (non-blocking)
        if (strtoupper(substr(PHP_OS, 0, 3)) === 'WIN') {
            pclose(popen('start /B ' . $postProcCmd, 'r'));
        } else {
            exec($postProcCmd . ' > /dev/null 2>&1 &');
        }

        error_log('[MercadoPago] email processor queued: order=' . $externalReference);
    }

    svmp_webhook_response(200, 'processed');
} catch (SvMercadoPagoApiException $e) {
    error_log('[MercadoPago] webhook API failure: resource=' . substr($dataId, 0, 80) . ' code=' . $e->publicCode);
    svmp_webhook_response($e->httpStatus === 422 ? 200 : 502, $e->publicCode);
} catch (Throwable $e) {
    error_log('[MercadoPago] webhook internal failure: resource=' . substr($dataId, 0, 80) . ' type=' . get_class($e));
    svmp_webhook_response(500, 'internal_error');
}

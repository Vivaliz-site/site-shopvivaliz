<?php

declare(strict_types=1);

header_remove('X-Powered-By');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

require_once dirname(__DIR__, 2) . '/includes/mercadopago-gateway.php';

function svmp_preference_response(int $status, array $payload): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    svmp_preference_response(405, ['ok' => false, 'error' => 'method_not_allowed']);
}

$raw = (string)file_get_contents('php://input');
if ($raw === '' || strlen($raw) > 10000) {
    svmp_preference_response(400, ['ok' => false, 'error' => 'invalid_request']);
}
$input = json_decode($raw, true);
if (!is_array($input)) {
    svmp_preference_response(400, ['ok' => false, 'error' => 'invalid_json']);
}

$orderNumber = trim((string)($input['order_number'] ?? ''));
$sessionToken = trim((string)($input['payment_session_token'] ?? ''));
$path = svmp_find_order_path($orderNumber);
if ($path === '') {
    svmp_preference_response(404, ['ok' => false, 'error' => 'order_not_found']);
}

$handle = fopen($path, 'r+');
if ($handle === false || !flock($handle, LOCK_EX)) {
    if (is_resource($handle)) {
        fclose($handle);
    }
    svmp_preference_response(503, ['ok' => false, 'error' => 'order_lock_unavailable']);
}

try {
    rewind($handle);
    $order = json_decode((string)stream_get_contents($handle), true);
    if (!is_array($order) || !svmp_session_matches($order, $sessionToken)) {
        svmp_preference_response(403, ['ok' => false, 'error' => 'invalid_payment_session']);
    }
    if (($order['payment_method'] ?? '') !== 'mercado_pago') {
        svmp_preference_response(409, ['ok' => false, 'error' => 'payment_method_mismatch']);
    }

    $existing = is_array($order['mercadopago']['preference'] ?? null) ? $order['mercadopago']['preference'] : [];
    if ((string)($existing['checkout_url'] ?? '') !== '') {
        svmp_preference_response(200, [
            'ok' => true,
            'reused' => true,
            'order_number' => $orderNumber,
            'checkout_url' => (string)$existing['checkout_url'],
        ]);
    }

    $accessToken = svmp_env('MERCADOPAGO_ACCESS_TOKEN');
    if ($accessToken === '') {
        svmp_preference_response(503, ['ok' => false, 'error' => 'gateway_unconfigured']);
    }
    $preference = svmp_create_preference($order, $accessToken);
    $order['status'] = 'payment_pending';
    $order['mercadopago'] = is_array($order['mercadopago'] ?? null) ? $order['mercadopago'] : [];
    $order['mercadopago']['provider'] = 'checkout_pro';
    $order['mercadopago']['preference'] = [
        'id' => $preference['preference_id'],
        'checkout_url' => $preference['checkout_url'],
        'created_at' => date(DATE_ATOM),
    ];
    $encoded = json_encode($order, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    rewind($handle);
    ftruncate($handle, 0);
    if (fwrite($handle, $encoded) === false || !fflush($handle)) {
        error_log('[MercadoPago] preference created but order persistence failed: order=' . $orderNumber);
        svmp_preference_response(500, ['ok' => false, 'error' => 'preference_persistence_failed']);
    }

    error_log('[MercadoPago] preference created: order=' . $orderNumber . ' preference=' . $preference['preference_id']);
    svmp_preference_response(201, [
        'ok' => true,
        'reused' => false,
        'order_number' => $orderNumber,
        'checkout_url' => $preference['checkout_url'],
    ]);
} catch (SvMercadoPagoApiException $e) {
    error_log('[MercadoPago] preference API failure: order=' . $orderNumber . ' code=' . $e->publicCode);
    svmp_preference_response($e->httpStatus, ['ok' => false, 'error' => $e->publicCode]);
} catch (Throwable $e) {
    error_log('[MercadoPago] preference internal failure: order=' . $orderNumber . ' type=' . get_class($e));
    svmp_preference_response(500, ['ok' => false, 'error' => 'internal_error']);
} finally {
    flock($handle, LOCK_UN);
    fclose($handle);
}

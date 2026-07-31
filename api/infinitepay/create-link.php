<?php
declare(strict_types=1);

header_remove('X-Powered-By');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

require_once dirname(__DIR__, 2) . '/includes/infinitepay-gateway.php';
require_once dirname(__DIR__, 2) . '/includes/mercadopago-gateway.php';
require_once dirname(__DIR__) . '/emails/send-order-notification.php';

function svip_link_response(int $status, array $payload): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function svip_link_persist_order($handle, array $order, string $orderNumber): void
{
    $encoded = json_encode($order, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    rewind($handle);
    ftruncate($handle, 0);
    if (fwrite($handle, $encoded) === false || !fflush($handle)) {
        error_log('[InfinitePay] order persistence failed: order=' . $orderNumber);
        svip_link_response(500, ['ok' => false, 'error' => 'link_persistence_failed']);
    }
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    svip_link_response(405, ['ok' => false, 'error' => 'method_not_allowed']);
}

$raw = (string)file_get_contents('php://input');
if ($raw === '' || strlen($raw) > 10000) {
    svip_link_response(400, ['ok' => false, 'error' => 'invalid_request']);
}
$input = json_decode($raw, true);
if (!is_array($input)) {
    svip_link_response(400, ['ok' => false, 'error' => 'invalid_json']);
}

$orderNumber = trim((string)($input['order_number'] ?? ''));
$sessionToken = trim((string)($input['payment_session_token'] ?? ''));
$path = svmp_find_order_path($orderNumber);
if ($path === '') {
    svip_link_response(404, ['ok' => false, 'error' => 'order_not_found']);
}

$handle = fopen($path, 'r+');
if ($handle === false || !flock($handle, LOCK_EX)) {
    if (is_resource($handle)) {
        fclose($handle);
    }
    svip_link_response(503, ['ok' => false, 'error' => 'order_lock_unavailable']);
}

try {
    rewind($handle);
    $order = json_decode((string)stream_get_contents($handle), true);
    if (!is_array($order) || !svmp_session_matches($order, $sessionToken)) {
        svip_link_response(403, ['ok' => false, 'error' => 'invalid_payment_session']);
    }
    if (($order['payment_method'] ?? '') !== 'infinitepay') {
        svip_link_response(409, ['ok' => false, 'error' => 'payment_method_mismatch']);
    }

    $existing = is_array($order['infinitepay'] ?? null) ? $order['infinitepay'] : [];
    if ((string)($existing['checkout_url'] ?? '') !== '') {
        svip_link_response(200, [
            'ok' => true,
            'reused' => true,
            'order_number' => $orderNumber,
            'checkout_url' => (string)$existing['checkout_url'],
        ]);
    }

    $link = svip_create_link($order);
    $order['status'] = 'payment_pending';
    $order['infinitepay'] = [
        'provider' => 'external_checkout',
        'checkout_url' => $link['checkout_url'],
        'slug' => $link['slug'],
        'created_at' => date(DATE_ATOM),
        'raw' => $link['raw'],
    ];
    svip_link_persist_order($handle, $order, $orderNumber);

    try {
        svem_send_order_email($order, 'payment_link_generated');
    } catch (Throwable $e) {
        error_log('[InfinitePay] email failure: order=' . $orderNumber . ' type=' . get_class($e));
    }

    svip_link_response(201, [
        'ok' => true,
        'reused' => false,
        'order_number' => $orderNumber,
        'checkout_url' => $link['checkout_url'],
    ]);
} catch (SvInfinitePayApiException $e) {
    error_log('[InfinitePay] link API failure: order=' . $orderNumber . ' code=' . $e->publicCode . ' details=' . json_encode($e->details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    svip_link_response($e->httpStatus, ['ok' => false, 'error' => $e->publicCode, 'details' => $e->details]);
} catch (Throwable $e) {
    error_log('[InfinitePay] link internal failure: order=' . $orderNumber . ' type=' . get_class($e));
    svip_link_response(500, ['ok' => false, 'error' => 'internal_error']);
} finally {
    flock($handle, LOCK_UN);
    fclose($handle);
}

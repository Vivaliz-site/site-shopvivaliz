<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/account-chrome.php';
require_once __DIR__ . '/../../includes/pdo-database.php';
require_once __DIR__ . '/../../includes/account-schema.php';
require_once __DIR__ . '/../../includes/tiny-order-push.php';

$user = sv_account_require_login();
sv_account_ensure_schema();
$orderId = (int)($_GET['order_id'] ?? 0);
if ($orderId <= 0) {
    http_response_code(400);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => 'invalid_order_id']);
    exit;
}
$pdo = sv_pdo();
$stmt = $pdo->prepare('SELECT id, user_id, order_number, olist_order_id, order_status, tracking_number, tracking_url, estimated_delivery FROM orders WHERE id = :id AND user_id = :uid LIMIT 1');
$stmt->execute([':id' => $orderId, ':uid' => (int)$user['id']]);
$order = $stmt->fetch(PDO::FETCH_ASSOC);
if (!is_array($order)) {
    http_response_code(404);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => 'order_not_found']);
    exit;
}

$erpOrderId = trim((string)($order['olist_order_id'] ?? ''));
$erpPayload = [];
if ($erpOrderId !== '' && svtop_tiny_credentials_configured()) {
    $token = svtop_tiny_get_token();
    if ($token !== '') {
        $resp = svtop_tiny_get_order($erpOrderId, $token);
        if (($resp['status'] ?? 0) === 200 && is_array($resp['json'] ?? null)) {
            $erpPayload = $resp['json'];
            $pedido = is_array($erpPayload['pedido'] ?? null) ? $erpPayload['pedido'] : $erpPayload;
            $tracking = svtop_tiny_first_non_empty([
                $pedido['codigoRastreamento'] ?? '',
                $pedido['rastreamento']['codigo'] ?? '',
                $pedido['transportador']['codigoRastreamento'] ?? '',
            ]);
            $trackingUrl = svtop_tiny_first_non_empty([
                $pedido['urlRastreamento'] ?? '',
                $pedido['rastreamento']['url'] ?? '',
                $pedido['transportador']['urlRastreamento'] ?? '',
            ]);
            $estimated = svtop_tiny_first_non_empty([$pedido['dataPrevista'] ?? '', $pedido['dataEntrega'] ?? '']);
            if ($tracking !== '' || $trackingUrl !== '' || $estimated !== '') {
                $up = $pdo->prepare('UPDATE orders SET tracking_number = COALESCE(NULLIF(:tracking, ""), tracking_number), tracking_url = COALESCE(NULLIF(:url, ""), tracking_url), estimated_delivery = COALESCE(NULLIF(:estimated, ""), estimated_delivery), updated_at = NOW() WHERE id = :id AND user_id = :uid');
                $up->execute([':tracking' => $tracking, ':url' => $trackingUrl, ':estimated' => substr($estimated, 0, 10), ':id' => $orderId, ':uid' => (int)$user['id']]);
                if ($tracking !== '') $order['tracking_number'] = $tracking;
                if ($trackingUrl !== '') $order['tracking_url'] = $trackingUrl;
                if ($estimated !== '') $order['estimated_delivery'] = substr($estimated, 0, 10);
            }
        }
    }
}

header('Content-Type: application/json; charset=utf-8');
echo json_encode([
    'ok' => true,
    'tracking' => [
        'order_number' => (string)($order['order_number'] ?? ''),
        'erp_order_id' => $erpOrderId,
        'status' => (string)($order['order_status'] ?? ''),
        'tracking_number' => (string)($order['tracking_number'] ?? ''),
        'tracking_url' => (string)($order['tracking_url'] ?? ''),
        'estimated_delivery' => (string)($order['estimated_delivery'] ?? ''),
        'erp_checked' => $erpPayload !== [],
    ],
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

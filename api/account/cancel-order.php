<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once dirname(__DIR__, 2) . '/includes/account-chrome.php';
require_once dirname(__DIR__, 2) . '/includes/pdo-database.php';
require_once dirname(__DIR__, 2) . '/includes/account-schema.php';
require_once dirname(__DIR__, 2) . '/includes/csrf.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'method_not_allowed']);
    exit;
}

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'not_authenticated']);
    exit;
}

$body = json_decode(file_get_contents('php://input') ?: '', true);
$body = is_array($body) ? $body : [];

if (!sv_csrf_valid('account-actions', $body['csrf_token'] ?? null)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'invalid_csrf']);
    exit;
}

$orderId = (int)($body['order_id'] ?? 0);
if ($orderId <= 0) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'invalid_order_id']);
    exit;
}

$cancellableStatuses = ['aguardando_pagamento', 'pagamento_aprovado'];

try {
    sv_account_ensure_schema();
    $pdo = sv_pdo();
    $userId = (int)$_SESSION['user_id'];

    $stmt = $pdo->prepare('SELECT id, order_status FROM orders WHERE id = :id AND user_id = :uid LIMIT 1');
    $stmt->execute([':id' => $orderId, ':uid' => $userId]);
    $order = $stmt->fetch();

    if (!$order) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'order_not_found']);
        exit;
    }

    if (!in_array($order['order_status'], $cancellableStatuses, true)) {
        http_response_code(409);
        echo json_encode(['ok' => false, 'error' => 'not_cancellable', 'message' => 'Este pedido já está em separação/envio e não pode mais ser cancelado automaticamente.']);
        exit;
    }

    $update = $pdo->prepare('UPDATE orders SET order_status = :status, updated_at = NOW() WHERE id = :id');
    $update->execute([':status' => 'cancelamento_solicitado', ':id' => $orderId]);

    echo json_encode(['ok' => true]);
} catch (Throwable $e) {
    error_log('[MinhaConta] cancel-order failed: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'internal_error']);
}

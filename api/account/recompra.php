<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once dirname(__DIR__, 2) . '/includes/account-chrome.php';
require_once dirname(__DIR__, 2) . '/includes/pdo-database.php';
require_once dirname(__DIR__, 2) . '/includes/account-schema.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'not_authenticated']);
    exit;
}

$orderId = (int)($_GET['order_id'] ?? 0);
if ($orderId <= 0) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'invalid_order_id']);
    exit;
}

try {
    sv_account_ensure_schema();
    $pdo = sv_pdo();
    $userId = (int)$_SESSION['user_id'];

    $stmt = $pdo->prepare('SELECT items_json FROM orders WHERE id = :id AND user_id = :uid LIMIT 1');
    $stmt->execute([':id' => $orderId, ':uid' => $userId]);
    $order = $stmt->fetch();

    if (!$order || empty($order['items_json'])) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'items_unavailable']);
        exit;
    }

    $items = json_decode((string)$order['items_json'], true);
    if (!is_array($items)) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => 'invalid_items']);
        exit;
    }

    $cleanItems = [];
    foreach ($items as $item) {
        if (!is_array($item) || empty($item['sku'])) {
            continue;
        }
        $cleanItems[] = [
            'sku' => (string)$item['sku'],
            'name' => (string)($item['name'] ?? $item['sku']),
            'price' => (float)($item['price'] ?? 0),
            'quantity' => max(1, (int)($item['quantity'] ?? 1)),
        ];
    }

    echo json_encode(['ok' => true, 'items' => $cleanItems]);
} catch (Throwable $e) {
    error_log('[MinhaConta] recompra failed: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'internal_error']);
}

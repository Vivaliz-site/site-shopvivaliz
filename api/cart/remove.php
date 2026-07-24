<?php
/**
 * API: Remover produto do carrinho
 * POST /api/cart/remove
 */

declare(strict_types=1);

require_once __DIR__ . '/../../includes/secure-session.php';
require_once __DIR__ . '/../../includes/input-validator.php';
require_once __DIR__ . '/../../includes/cors.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

// ✅ Handle CORS
if (CorsManager::handlePreflight()) {
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

// ✅ Parse input
$raw = file_get_contents('php://input');
$data = json_decode($raw, true);

$v = validator();
$sku = $v->requireString('sku', 1, 80, 'SKU');

if ($sku === null) {
    http_response_code(400);
    echo json_encode(['error' => 'SKU is required']);
    exit;
}

// ✅ Remove from cart
if (isset($_SESSION['cart'][$sku])) {
    unset($_SESSION['cart'][$sku]);
}

// ✅ Recalculate totals
$total = 0;
$items = 0;

foreach ($_SESSION['cart'] ?? [] as $item) {
    $total += $item['price'] * $item['quantity'];
    $items += $item['quantity'];
}

http_response_code(200);
echo json_encode([
    'ok' => true,
    'message' => 'Product removed from cart',
    'sku' => $sku,
    'cart' => [
        'total_items' => $items,
        'total_price' => round($total, 2),
        'items_count' => count($_SESSION['cart'] ?? [])
    ]
]);

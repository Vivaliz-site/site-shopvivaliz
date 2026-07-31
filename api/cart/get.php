<?php
/**
 * API: Obter carrinho
 * GET /api/cart/get
 */

declare(strict_types=1);

require_once __DIR__ . '/../../includes/secure-session.php';
require_once __DIR__ . '/../../includes/cors.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

// ✅ Handle CORS
if (CorsManager::handlePreflight()) {
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

// ✅ Get cart from session
$cart = $_SESSION['cart'] ?? [];
$total = 0;
$items = 0;

foreach ($cart as $item) {
    $total += $item['price'] * $item['quantity'];
    $items += $item['quantity'];
}

http_response_code(200);
echo json_encode([
    'ok' => true,
    'cart' => $cart,
    'summary' => [
        'total_items' => $items,
        'total_price' => round($total, 2),
        'items_count' => count($cart)
    ]
]);

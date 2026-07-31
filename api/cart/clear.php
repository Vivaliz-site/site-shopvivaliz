<?php
/**
 * API: Limpar carrinho
 * POST /api/cart/clear
 *
 * Remove todos os itens do carrinho do usuário
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

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

// ✅ Clear cart
$_SESSION['cart'] = [];

http_response_code(200);
echo json_encode([
    'ok' => true,
    'message' => 'Cart cleared',
    'cart' => [
        'total_items' => 0,
        'total_price' => 0.0,
        'items_count' => 0
    ]
]);

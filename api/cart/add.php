<?php
/**
 * API: Adicionar produto ao carrinho
 * POST /api/cart/add
 */

declare(strict_types=1);

require_once __DIR__ . '/../../includes/secure-session.php';
require_once __DIR__ . '/../../includes/input-validator.php';
require_once __DIR__ . '/../../includes/rate-limiter.php';
require_once __DIR__ . '/../../includes/cors.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

// ✅ Handle CORS preflight
if (CorsManager::handlePreflight()) {
    exit;
}

// ✅ Validate method
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed', 'allowed' => ['POST']]);
    exit;
}

// ✅ Rate limiting
$clientIp = $_SERVER['REMOTE_ADDR'];
if (!RateLimiter::isAllowed('cart_add_' . $clientIp, 20, 60)) {
    http_response_code(429);
    echo json_encode(['error' => 'Too many requests']);
    exit;
}

// ✅ Parse JSON input
$raw = file_get_contents('php://input');
$data = json_decode($raw, true);

if (!is_array($data)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid JSON']);
    exit;
}

// ✅ Validate input
$v = validator();
$sku = $v->requireString('sku', 1, 80, 'SKU');
$quantity = $v->getInteger('quantity', 1, 1, 99);

if ($sku === null) {
    http_response_code(400);
    echo json_encode(['error' => 'SKU is required', 'details' => $v->getErrors()]);
    exit;
}

// ✅ Initialize cart in session
if (!isset($_SESSION['cart'])) {
    $_SESSION['cart'] = [];
}

// ✅ Validate product exists and has valid price
try {
    require_once __DIR__ . '/../../config/database.php';

    $db = Database::getInstance()->getConnection();
    $stmt = $db->prepare('SELECT id, sku, name, price, stock FROM products WHERE sku = ? LIMIT 1');

    if (!$stmt) {
        http_response_code(500);
        echo json_encode(['error' => 'Database error']);
        exit;
    }

    $stmt->bind_param('s', $sku);
    $stmt->execute();
    $result = $stmt->get_result();
    $product = $result->fetch_assoc();

    if (!$product) {
        http_response_code(404);
        echo json_encode(['error' => 'Product not found', 'sku' => $sku]);
        exit;
    }

    $price = (float)($product['price'] ?? 0);
    $stock = (int)($product['stock'] ?? 0);

    // ✅ Validate price
    if ($price <= 0) {
        http_response_code(422);
        echo json_encode(['error' => 'Product has invalid price', 'price' => $price]);
        exit;
    }

    // ✅ Validate stock
    if ($stock < $quantity) {
        http_response_code(409);
        echo json_encode([
            'error' => 'Insufficient stock',
            'sku' => $sku,
            'requested' => $quantity,
            'available' => $stock
        ]);
        exit;
    }

    // ✅ Add to cart
    if (isset($_SESSION['cart'][$sku])) {
        $_SESSION['cart'][$sku]['quantity'] += $quantity;
    } else {
        $_SESSION['cart'][$sku] = [
            'sku' => $sku,
            'name' => $product['name'],
            'price' => round($price, 2),
            'quantity' => $quantity,
            'product_id' => $product['id']
        ];
    }

    // ✅ Calculate cart totals
    $cartTotal = 0;
    $cartItems = 0;

    foreach ($_SESSION['cart'] as $item) {
        $itemTotal = $item['price'] * $item['quantity'];
        $cartTotal += $itemTotal;
        $cartItems += $item['quantity'];
    }

    // ✅ Return success
    http_response_code(200);
    echo json_encode([
        'ok' => true,
        'message' => 'Product added to cart',
        'product' => [
            'sku' => $sku,
            'name' => $product['name'],
            'price' => round($price, 2),
            'quantity' => $_SESSION['cart'][$sku]['quantity']
        ],
        'cart' => [
            'total_items' => $cartItems,
            'total_price' => round($cartTotal, 2),
            'items_count' => count($_SESSION['cart'])
        ]
    ]);

} catch (Exception $e) {
    error_log('[API Cart] Error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Server error']);
    exit;
}

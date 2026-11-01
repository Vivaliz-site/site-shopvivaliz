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
require_once __DIR__ . '/../../includes/ml-event-tracker.php';

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
// Rodada 7 (2026-08-19): o IP ja esta embutido na chave de arquivo
// (hash(IP+User-Agent) em svorl_client_key()) -- colocar tambem no
// identificador/escopo criava um diretorio novo por IP visto neste escopo,
// crescimento sem teto em storage/rate-limit/. Ver R7-9 no relatorio da
// Rodada 7.
if (!RateLimiter::isAllowed('cart_add', 20, 60)) {
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

foreach ($data as $key => $value) {
    if (!is_string($key) || array_key_exists($key, $_POST)) {
        continue;
    }
    $_POST[$key] = $value;
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
    require_once __DIR__ . '/../../includes/catalog-runtime.php';
    require_once __DIR__ . '/../../includes/cart-authoritative-product.php';

    // The catalog runtime is derived from official Olist/Tiny v3 and Amazon 991.
    // Do not use the local MySQL products table: it can contain obsolete IDs,
    // prices and physical-stock figures after ERP product recreation.
    $product = svca_find_catalog_product(svcr_products(), $sku);
    if (!is_array($product)) {
        http_response_code(404);
        echo json_encode(['error' => 'Product not found or ambiguous in ERP', 'sku' => $sku]);
        exit;
    }

    $canonicalSku = trim((string)($product['sku'] ?? ''));
    // Count prior quantities across case variants without mutating the cart
    // until the authoritative price/stock check succeeds.
    $existingQuantity = 0;
    $existingKeys = [];
    foreach ($_SESSION['cart'] as $storedKey => $stored) {
        if (!is_array($stored)) continue;
        $storedSku = trim((string)($stored['sku'] ?? $storedKey));
        if (strcasecmp($storedSku, $canonicalSku) !== 0) continue;
        $existingQuantity += (int)($stored['quantity'] ?? 0);
        $existingKeys[] = $storedKey;
    }
    $quote = svca_quote_cart_add($product, $quantity, $existingQuantity);
    if (!$quote['ok']) {
        http_response_code((int)$quote['status']);
        echo json_encode([
            'error' => $quote['error'] ?? 'Invalid ERP product',
            'sku' => $canonicalSku,
            'requested' => $quote['requested'] ?? $quantity,
            'available' => $quote['available'] ?? 0,
        ]);
        exit;
    }

    // Commit the session mutation only after validating total quantity.
    foreach ($existingKeys as $key) unset($_SESSION['cart'][$key]);
    $sku = $canonicalSku;
    $price = (float)$quote['price'];
    $_SESSION['cart'][$sku] = [
        'sku' => $sku,
        'name' => (string)($product['name'] ?? $sku),
        'price' => $price,
        'quantity' => (int)$quote['quantity'],
        'product_id' => (string)$quote['product_id'],
    ];

    // ✅ Calculate cart totals
    $cartTotal = 0;
    $cartItems = 0;

    foreach ($_SESSION['cart'] as $item) {
        $itemTotal = $item['price'] * $item['quantity'];
        $cartTotal += $itemTotal;
        $cartItems += $item['quantity'];
    }

    svml_track_event('add_to_cart', (string)($product['id'] ?? $sku), [
        'source' => 'cart_api',
        'sku' => $sku,
        'quantity' => $quantity,
    ]);

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

} catch (Throwable $e) {
    error_log('[API Cart] Error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Server error']);
    exit;
}

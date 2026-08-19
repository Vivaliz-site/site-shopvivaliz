<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'method_not_allowed']);
    exit;
}

require_once __DIR__ . '/../../includes/pdo-database.php';
require_once __DIR__ . '/../../includes/account-schema.php';
require_once __DIR__ . '/../../includes/order-rate-limit.php';
require_once __DIR__ . '/../../includes/catalog-runtime.php';

if (!svorl_allow(20, 3600, 'cart-restore')) {
    http_response_code(429);
    echo json_encode(['ok' => false, 'error' => 'rate_limited']);
    exit;
}

$body = json_decode(file_get_contents('php://input') ?: '', true);
$token = is_array($body) ? trim((string)($body['token'] ?? '')) : '';
if (!preg_match('/^[A-Za-z0-9_-]{40,64}$/', $token)) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'invalid_token']);
    exit;
}

try {
    sv_account_ensure_schema();
    $pdo = sv_pdo();
    if (!($pdo instanceof PDO)) {
        throw new RuntimeException('database_unavailable');
    }

    $stmt = $pdo->prepare(
        'SELECT cart_snapshot
         FROM checkout_abandonments
         WHERE recovery_token_hash = :token_hash
           AND recovery_token_expires_at IS NOT NULL
           AND recovery_token_expires_at >= NOW()
           AND recovered_at IS NULL
         ORDER BY id DESC
         LIMIT 1'
    );
    $stmt->execute([':token_hash' => hash('sha256', $token)]);
    $snapshotRaw = $stmt->fetchColumn();
    if (!is_string($snapshotRaw) || $snapshotRaw === '') {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'expired_or_unavailable']);
        exit;
    }

    $snapshot = json_decode($snapshotRaw, true);
    $snapshot = is_array($snapshot) ? $snapshot : [];

    $catalogBySku = [];
    foreach (svcr_products() as $product) {
        if (!is_array($product)) {
            continue;
        }
        $sku = trim((string)($product['sku'] ?? ''));
        if ($sku !== '') {
            $catalogBySku[strtolower($sku)] = $product;
        }
    }

    $items = [];
    foreach (array_slice($snapshot, 0, 10) as $saved) {
        if (!is_array($saved)) {
            continue;
        }
        $sku = trim((string)($saved['sku'] ?? ''));
        if ($sku === '') {
            continue;
        }
        $product = $catalogBySku[strtolower($sku)] ?? null;
        if (!is_array($product)) {
            continue;
        }

        $price = (float)($product['price'] ?? 0);
        $stock = max(0, (int)($product['stock'] ?? 0));
        if ($price <= 0 || $stock <= 0) {
            continue;
        }

        $requestedQuantity = max(1, min(20, (int)($saved['quantity'] ?? 1)));
        $quantity = min($requestedQuantity, $stock);
        if ($quantity <= 0) {
            continue;
        }

        $items[] = [
            'id' => (string)($product['id'] ?? $sku),
            'sku' => $sku,
            'name' => trim((string)($product['name'] ?? $sku)),
            'price' => $price,
            'image_url' => trim((string)($product['image_url'] ?? '')),
            'olist_product_id' => (string)($product['olist_product_id'] ?? $product['id'] ?? ''),
            'quantity' => $quantity,
        ];
    }

    if ($items === []) {
        http_response_code(409);
        echo json_encode(['ok' => false, 'error' => 'no_available_items']);
        exit;
    }

    echo json_encode([
        'ok' => true,
        'items' => $items,
        'count' => count($items),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    error_log('[restore-abandonment] failed: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'restore_failed']);
}
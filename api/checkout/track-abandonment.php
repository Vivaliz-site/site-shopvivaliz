<?php
declare(strict_types=1);

/**
 * api/checkout/track-abandonment.php
 *
 * Registra (upsert) que um cliente chegou a preencher e-mail no checkout,
 * pra permitir e-mail de recuperacao de carrinho abandonado depois. Chamado
 * via JS do checkout.php quando o campo de e-mail perde foco -- nunca
 * bloqueia o fluxo de compra (fire-and-forget, falha silenciosa).
 *
 * Nao recebe nem grava dados de pagamento. session_token e gerado no
 * cliente (crypto.randomUUID, guardado em sessionStorage) so pra permitir
 * upsert idempotente sem expor nenhum identificador de pedido real.
 */

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false]);
    exit;
}

require_once __DIR__ . '/../../includes/pdo-database.php';
require_once __DIR__ . '/../../includes/account-schema.php';
require_once __DIR__ . '/../../includes/order-rate-limit.php';
require_once __DIR__ . '/../../includes/catalog-runtime.php';
require_once __DIR__ . '/../../src/Commerce/AbandonedCartRecoverySchema.php';

$originHeader = trim((string)($_SERVER['HTTP_ORIGIN'] ?? ''));
if ($originHeader !== '') {
    $originHost = strtolower((string)parse_url($originHeader, PHP_URL_HOST));
    if (!in_array($originHost, ['shopvivaliz.com.br', 'www.shopvivaliz.com.br', 'localhost', '127.0.0.1'], true)) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'origin_rejected']);
        exit;
    }
}
if (!svorl_allow(5, 3600, 'abandonment')) {
    http_response_code(429);
    echo json_encode(['ok' => false, 'error' => 'rate_limited']);
    exit;
}

$body = json_decode(file_get_contents('php://input') ?: '', true);
if (!is_array($body)) {
    http_response_code(400);
    echo json_encode(['ok' => false]);
    exit;
}

if (trim((string)($body['website'] ?? '')) !== '') {
    echo json_encode(['ok' => true]);
    exit;
}

$email = strtolower(trim((string)($body['email'] ?? '')));
$sessionToken = trim((string)($body['session_token'] ?? ''));
$name = trim((string)($body['name'] ?? ''));
$cartItems = is_array($body['cart_items'] ?? null) ? $body['cart_items'] : [];
$cartTotal = (float)($body['cart_total'] ?? 0);

if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 160) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'invalid_email']);
    exit;
}
if (!preg_match('/^[a-f0-9-]{16,64}$/i', $sessionToken)) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'invalid_session_token']);
    exit;
}

$catalog = array_values(array_filter(svcr_products(), 'is_array'));
$bySku = [];
$byName = [];
foreach ($catalog as $product) {
    $sku = trim((string)($product['sku'] ?? ''));
    $productName = trim((string)($product['name'] ?? ''));
    if ($sku !== '') {
        $bySku[strtolower($sku)] = $product;
    }
    if ($productName !== '') {
        $key = function_exists('mb_strtolower') ? mb_strtolower($productName, 'UTF-8') : strtolower($productName);
        $byName[$key][] = $product;
    }
}

$snapshot = [];
foreach (array_slice($cartItems, 0, 10) as $item) {
    if (!is_array($item)) {
        continue;
    }
    $sku = trim((string)($item['sku'] ?? ''));
    $itemName = trim((string)($item['name'] ?? ''));
    $quantity = max(1, min(20, (int)($item['quantity'] ?? 1)));
    $resolved = null;

    if ($sku !== '') {
        $resolved = $bySku[strtolower($sku)] ?? null;
    }
    if (!is_array($resolved) && $itemName !== '') {
        $nameKey = function_exists('mb_strtolower') ? mb_strtolower($itemName, 'UTF-8') : strtolower($itemName);
        $matches = $byName[$nameKey] ?? [];
        if (count($matches) === 1) {
            $resolved = $matches[0];
        }
    }
    if (!is_array($resolved)) {
        continue;
    }

    $resolvedSku = trim((string)($resolved['sku'] ?? ''));
    if ($resolvedSku === '') {
        continue;
    }
    $snapshot[] = [
        'sku' => mb_substr($resolvedSku, 0, 80),
        'quantity' => $quantity,
        'name' => mb_substr(trim((string)($resolved['name'] ?? $itemName)), 0, 120),
    ];
}
$cartSnapshot = json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

try {
    sv_account_ensure_schema();
    $pdo = sv_pdo();
    if (!($pdo instanceof PDO)) {
        throw new RuntimeException('database_unavailable');
    }
    svacr_ensure_restore_schema($pdo);

    $stmt = $pdo->prepare(
        'INSERT INTO checkout_abandonments (email, customer_name, cart_snapshot, cart_total, session_token, created_at, updated_at)
         VALUES (:email, :name, :snapshot, :total, :token, NOW(), NOW())
         ON DUPLICATE KEY UPDATE
            email = VALUES(email),
            customer_name = VALUES(customer_name),
            cart_snapshot = VALUES(cart_snapshot),
            cart_total = VALUES(cart_total),
            recovery_token_hash = NULL,
            recovery_token_expires_at = NULL,
            updated_at = NOW()'
    );
    $stmt->execute([
        ':email' => $email,
        ':name' => $name !== '' ? mb_substr($name, 0, 120) : null,
        ':snapshot' => $cartSnapshot,
        ':total' => round(max(0, $cartTotal), 2),
        ':token' => $sessionToken,
    ]);

    echo json_encode(['ok' => true]);
} catch (Throwable $e) {
    error_log('[track-abandonment] failed: ' . $e->getMessage());
    echo json_encode(['ok' => false]);
}
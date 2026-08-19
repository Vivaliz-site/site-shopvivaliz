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

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false]);
    exit;
}

require_once __DIR__ . '/../../includes/pdo-database.php';
require_once __DIR__ . '/../../includes/account-schema.php';
require_once __DIR__ . '/../../includes/order-rate-limit.php';

// Rodada 9 (2026-08-19): este endpoint aceitava POST anonimo sem rate limit,
// sem checagem de origem e sem honeypot -- um POST direto (sem passar pelo
// checkout de verdade) inseria uma linha por UUID gerado, e
// scripts/send-abandoned-cart-emails.php (cron */30) enviava e-mail real do
// dominio da loja pro endereco informado, com nome/itens controlados por
// quem chamou o endpoint (~4.800 e-mails/dia possiveis). Os vizinhos
// api/newsletter/subscribe.php e api/contact.php ja tinham essas defesas;
// replicado aqui no mesmo padrao. Ver R9-4 no relatorio da Rodada 9.
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

// Honeypot: campo invisivel no formulario real, preenchido apenas por bots.
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

// Snapshot leve: so nome dos itens, sem preco unitario/SKU sensivel, pra
// poder mencionar "os itens que voce deixou no carrinho" no e-mail.
$snapshotNames = [];
foreach (array_slice($cartItems, 0, 10) as $item) {
    if (is_array($item) && isset($item['name'])) {
        $snapshotNames[] = mb_substr(trim((string)$item['name']), 0, 120);
    }
}
$cartSnapshot = json_encode($snapshotNames, JSON_UNESCAPED_UNICODE);

try {
    sv_account_ensure_schema();
    $pdo = sv_pdo();

    $stmt = $pdo->prepare(
        'INSERT INTO checkout_abandonments (email, customer_name, cart_snapshot, cart_total, session_token, created_at, updated_at)
         VALUES (:email, :name, :snapshot, :total, :token, NOW(), NOW())
         ON DUPLICATE KEY UPDATE
            email = VALUES(email),
            customer_name = VALUES(customer_name),
            cart_snapshot = VALUES(cart_snapshot),
            cart_total = VALUES(cart_total),
            updated_at = NOW()'
    );
    $stmt->execute([
        ':email' => $email,
        ':name' => $name !== '' ? mb_substr($name, 0, 120) : null,
        ':snapshot' => $cartSnapshot,
        ':total' => round($cartTotal, 2),
        ':token' => $sessionToken,
    ]);

    echo json_encode(['ok' => true]);
} catch (Throwable $e) {
    error_log('[track-abandonment] failed: ' . $e->getMessage());
    // Falha silenciosa do ponto de vista do cliente -- isso nunca deve
    // atrapalhar o checkout em si.
    echo json_encode(['ok' => false]);
}

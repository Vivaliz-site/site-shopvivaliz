<?php
declare(strict_types=1);

header_remove('X-Powered-By');
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');
require_once dirname(__DIR__, 2) . '/includes/catalog-runtime.php';
require_once dirname(__DIR__, 2) . '/includes/tiny-order-push.php';

function svo_json(int $status, array $payload): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function svo_root(): string
{
    return dirname(__DIR__, 2);
}

/** Mapa sku -> estoque atual, lido do catalogo (fonte usada pelo storefront). */
function svo_stock_map(): array
{
    static $map = null;
    if ($map !== null) {
        return $map;
    }
    $map = [];
    $catalog = svcr_products();
    if ($catalog !== []) {
            foreach ($catalog as $product) {
                if (!is_array($product)) {
                    continue;
                }
                $sku = trim((string)($product['sku'] ?? ''));
                if ($sku !== '') {
                    $map[$sku] = (int)($product['stock'] ?? 0);
                }
            }
    }
    return $map;
}

function svo_autodev_available(): bool
{
    static $loaded = null;
    if ($loaded !== null) {
        return $loaded;
    }
    $path = svo_root() . '/autodev/core/event_collector.php';
    if (!is_file($path) || !is_readable($path)) {
        $loaded = false;
        return false;
    }
    require_once $path;
    $loaded = function_exists('autodev_track');
    return $loaded;
}

function svo_order_dir(): string
{
    $preferred = svo_root() . '/storage/orders';
    if ((is_dir($preferred) || @mkdir($preferred, 0755, true)) && is_writable($preferred)) {
        return $preferred;
    }

    $fallback = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'shopvivaliz-orders';
    if ((is_dir($fallback) || @mkdir($fallback, 0755, true)) && is_writable($fallback)) {
        return $fallback;
    }

    return '';
}

function svo_append_legacy_order_log(array $order): void
{
    $logDir = svo_root() . '/logs';
    if (!is_dir($logDir)) {
        @mkdir($logDir, 0755, true);
    }

    $entry = [
        'id' => $order['order_number'] ?? '',
        'timestamp' => $order['created_at'] ?? date('c'),
        'cliente' => [
            'nome' => $order['customer']['name'] ?? '',
            'email' => $order['customer']['email'] ?? '',
            'telefone' => $order['customer']['phone'] ?? '',
            'endereco' => $order['customer']['address'] ?? '',
            'numero' => '',
            'complemento' => '',
            'cidade' => '',
            'cep' => $order['customer']['cep'] ?? '',
        ],
        'items' => $order['items'] ?? [],
        'payment_method' => $order['payment_method'] ?? 'pix',
        'status' => 'pendente_atendimento',
        'source' => 'checkout_site_api',
        'shipping_total' => round((float)($order['shipping_total'] ?? 0), 2),
        'shipping_label' => (string)($order['shipping_label'] ?? ''),
        'tiny_order_id' => (string)($order['tiny_order_id'] ?? ''),
        'tiny_push' => (string)($order['tiny_push'] ?? ''),
        'total' => round((float)($order['total'] ?? 0), 2),
    ];

    @file_put_contents(
        $logDir . '/pedidos.jsonl',
        json_encode($entry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL,
        FILE_APPEND | LOCK_EX
    );
}

function svo_payment_method(string $value): string
{
    $normalized = strtolower(trim($value));
    // 'mercado_pago' e o unico metodo oferecido no formulario real do checkout
    // (api/checkout, ver checkout.php) -- estava faltando aqui, entao todo
    // pedido pago via Mercado Pago era silenciosamente rebaixado para 'pix'
    // no backend, quebrando o fluxo real de pagamento (create-preference.php
    // nunca era chamado corretamente).
    $allowed = ['pix', 'boleto', 'whatsapp', 'transferencia', 'mercado_pago'];
    return in_array($normalized, $allowed, true) ? $normalized : 'pix';
}

function svo_payment_label(string $method): string
{
    return match ($method) {
        'boleto' => 'Boleto bancario',
        'whatsapp' => 'WhatsApp',
        'transferencia' => 'Transferencia bancaria',
        'mercado_pago' => 'Mercado Pago',
        default => 'PIX',
    };
}

function svo_payment_instructions(string $method): string
{
    return match ($method) {
        'boleto' => 'Boleto sujeito a emissao manual apos confirmacao do frete.',
        'whatsapp' => 'Pagamento e frete serao alinhados pelo atendimento no WhatsApp.',
        'transferencia' => 'Dados bancarios serao enviados pela equipe apos confirmacao do frete.',
        'mercado_pago' => 'Voce sera redirecionado para o checkout seguro do Mercado Pago.',
        default => 'Pagamento via PIX com confirmacao apos validacao do pedido.',
    };
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    svo_json(405, ['ok' => false, 'error' => 'method_not_allowed']);
}

$raw = file_get_contents('php://input') ?: '';
if (strlen($raw) > 200000) {
    svo_json(413, ['ok' => false, 'error' => 'payload_too_large']);
}
$body = json_decode($raw, true);
if (!is_array($body)) {
    svo_json(400, ['ok' => false, 'error' => 'invalid_json']);
}

$name = trim((string)($body['customer_name'] ?? ''));
$email = trim((string)($body['customer_email'] ?? ''));
$phone = trim((string)($body['customer_phone'] ?? ''));
$cep = preg_replace('/\D+/', '', (string)($body['cep'] ?? ''));
$address = trim((string)($body['address'] ?? ''));
$notes = trim((string)($body['notes'] ?? ''));
$paymentMethod = svo_payment_method((string)($body['payment_method'] ?? 'pix'));
$shippingTotal = max(0.0, (float)($body['shipping_total'] ?? 0));
$shippingLabel = trim((string)($body['shipping_label'] ?? ''));
$shippingService = trim((string)($body['shipping_service'] ?? ''));
$shippingCep = preg_replace('/\D+/', '', (string)($body['shipping_cep'] ?? $cep));
$items = is_array($body['items'] ?? null) ? $body['items'] : [];

if (strlen($name) > 120 || strlen($email) > 160 || strlen($phone) > 40 || strlen($address) > 300 || strlen($notes) > 1000) {
    svo_json(422, ['ok' => false, 'error' => 'field_too_long']);
}

if ($name === '' || !filter_var($email, FILTER_VALIDATE_EMAIL) || $phone === '' || strlen($cep) !== 8 || $address === '' || !$items) {
    svo_json(422, ['ok' => false, 'error' => 'missing_required_fields']);
}

if (count($items) > 100) {
    svo_json(422, ['ok' => false, 'error' => 'too_many_items']);
}

$cleanItems = [];
$itemsTotal = 0.0;
foreach ($items as $item) {
    if (!is_array($item)) continue;
    $sku = trim((string)($item['sku'] ?? ''));
    $itemName = trim((string)($item['name'] ?? $sku));
    $quantity = max(1, min(99, (int)($item['quantity'] ?? 1)));
    $price = max(0.0, (float)($item['price'] ?? 0));
    if (strlen($sku) > 80 || strlen($itemName) > 220) continue;
    if ($sku === '' || $itemName === '') continue;
    $itemsTotal += $price * $quantity;
    $cleanItems[] = [
        'sku' => $sku,
        'name' => $itemName,
        'quantity' => $quantity,
        'price' => round($price, 2),
        'olist_product_id' => trim((string)($item['olist_product_id'] ?? '')),
    ];
}

if (!$cleanItems) {
    svo_json(422, ['ok' => false, 'error' => 'empty_items']);
}

// Bloqueia venda de item sem estoque suficiente (validacao no servidor).
$stockMap = svo_stock_map();
$stockIssues = [];
foreach ($cleanItems as $ci) {
    if (!array_key_exists($ci['sku'], $stockMap)) {
        continue;
    }
    $available = $stockMap[$ci['sku']];
    if ($available <= 0 || $ci['quantity'] > $available) {
        $stockIssues[] = [
            'sku' => $ci['sku'],
            'name' => $ci['name'],
            'requested' => $ci['quantity'],
            'available' => max(0, $available),
        ];
    }
}
if ($stockIssues) {
    svo_json(409, [
        'ok' => false,
        'error' => 'insufficient_stock',
        'message' => 'Um ou mais itens do carrinho nao tem estoque suficiente.',
        'items' => $stockIssues,
    ]);
}

$notesParts = [];
if ($notes !== '') {
    $notesParts[] = $notes;
}
if ($shippingLabel !== '' || $shippingTotal > 0) {
    $shippingNote = trim(implode(' | ', array_filter([
        $shippingLabel !== '' ? 'Frete: ' . $shippingLabel : '',
        $shippingTotal > 0 ? 'Valor do frete: R$ ' . number_format($shippingTotal, 2, ',', '.') : '',
    ])));
    if ($shippingNote !== '') {
        $notesParts[] = $shippingNote;
    }
}

$grandTotal = $itemsTotal + $shippingTotal;

$orderNumber = 'SV' . date('YmdHis') . random_int(100, 999);
// Token de sessao de pagamento: o frontend (checkout.php) espera receber isso
// na resposta para poder chamar create-preference.php/create-boleto.php
// depois. Sem isso, svmp_session_matches() sempre rejeitava com
// invalid_payment_session e o fluxo Mercado Pago nunca completava.
$paymentSessionToken = bin2hex(random_bytes(32));
$record = [
    'order_number' => $orderNumber,
    'status' => 'pending_confirmation',
    'payment_session_hash' => hash('sha256', $paymentSessionToken),
    'customer' => [
        'name' => $name,
        'email' => $email,
        'phone' => $phone,
        'cep' => $cep,
        'address' => $address,
    ],
    'items' => $cleanItems,
    'items_total' => round($itemsTotal, 2),
    'shipping_total' => round($shippingTotal, 2),
    'shipping_label' => $shippingLabel,
    'shipping_service' => $shippingService,
    'shipping_cep' => $shippingCep,
    'total' => round($grandTotal, 2),
    'payment_method' => $paymentMethod,
    'payment_label' => svo_payment_label($paymentMethod),
    'notes' => implode("\n", $notesParts),
    'created_at' => date('c'),
    'source' => 'site_checkout',
];

$dir = svo_order_dir();
if ($dir === '') {
    svo_json(500, ['ok' => false, 'error' => 'order_storage_unavailable']);
}
$path = $dir . '/' . $orderNumber . '.json';
if (file_put_contents($path, json_encode($record, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX) === false) {
    svo_json(500, ['ok' => false, 'error' => 'order_write_failed']);
}

if (svo_autodev_available()) {
    autodev_track('order_complete', [
        'order_number' => $orderNumber,
        'total' => round($grandTotal, 2),
        'payment_method' => $paymentMethod,
        'items_count' => count($cleanItems),
        'items' => array_map(static function (array $item): array {
            return [
                'sku' => $item['sku'],
                'quantity' => $item['quantity'],
                'price' => $item['price'],
            ];
        }, $cleanItems),
    ]);
}

// Pedido so vai para o Tiny ERP quando o pagamento e de fato aprovado --
// isso acontece no webhook do Mercado Pago (api/webhook-mercadopago.php),
// nunca aqui na criacao. Antes este endpoint empurrava TODO pedido criado
// direto pro ERP, poluindo o Tiny com pedidos que o cliente nunca chegou
// a pagar.
file_put_contents($path, json_encode($record, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);
svo_append_legacy_order_log($record);

// Espelha o pedido na tabela MySQL `orders` (fonte usada por meus-pedidos.php
// e pelo webhook do ERP) -- antes disso a tabela nunca era populada aqui.
try {
    require_once dirname(__DIR__, 2) . '/includes/pdo-database.php';
    require_once dirname(__DIR__, 2) . '/includes/account-schema.php';
    sv_account_ensure_schema();

    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
    $sessionUserId = isset($_SESSION['user_id']) && is_numeric($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;

    $pdo = sv_pdo();
    $stmt = $pdo->prepare(
        'INSERT INTO orders (user_id, order_number, olist_order_id, email, order_total, order_status, payment_method, items_json, created_at)
         VALUES (:user_id, :order_number, :olist_order_id, :email, :total, :status, :payment_method, :items_json, NOW())'
    );
    $stmt->execute([
        ':user_id' => $sessionUserId,
        ':order_number' => $orderNumber,
        ':olist_order_id' => $tinyOrderId,
        ':email' => $email,
        ':total' => round($grandTotal, 2),
        ':status' => 'aguardando_pagamento',
        ':payment_method' => $paymentMethod,
        ':items_json' => json_encode($cleanItems, JSON_UNESCAPED_UNICODE),
    ]);
} catch (Throwable $e) {
    error_log('[OrderCreate] MySQL orders mirror failed: ' . $e->getMessage());
}

svo_json(200, [
    'ok' => true,
    'order_number' => $orderNumber,
    'payment_session_token' => $paymentSessionToken,
    'status' => 'pending_confirmation',
    'payment_method' => $paymentMethod,
    'payment_label' => $record['payment_label'],
    'message' => 'Pedido registrado para confirmacao manual de frete e pagamento.',
    'payment_instructions' => svo_payment_instructions($paymentMethod),
    'storage' => str_contains($dir, 'shopvivaliz-orders') ? 'fallback_temp' : 'storage_orders',
    'tiny_order_id' => $tinyOrderId,
    'tiny_push' => $tinyPushStatus,
    'subtotal' => round($itemsTotal, 2),
    'shipping_total' => round($shippingTotal, 2),
    'shipping_label' => $shippingLabel,
    'total' => round($grandTotal, 2),
]);

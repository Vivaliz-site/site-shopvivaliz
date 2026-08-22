<?php
declare(strict_types=1);

header_remove('X-Powered-By');
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');
require_once dirname(__DIR__, 2) . '/includes/catalog-runtime.php';
require_once dirname(__DIR__, 2) . '/includes/tiny-order-push.php';
require_once dirname(__DIR__, 2) . '/includes/cors.php';
require_once dirname(__DIR__, 2) . '/includes/idempotency.php';
require_once dirname(__DIR__, 2) . '/includes/rate-limiter.php';
require_once dirname(__DIR__, 2) . '/includes/input-validator.php';

// ✅ Handle CORS preflight requests
if (CorsManager::handlePreflight()) {
    exit;
}

// ✅ Check rate limiting (5 pedidos por minuto por IP)
$clientIp = $_SERVER['REMOTE_ADDR'];
// Rodada 7 (2026-08-19): IP ja esta na chave de arquivo (hash(IP+UA) em
// svorl_client_key()); tira-lo do identificador evita um diretorio novo por
// IP em storage/rate-limit/. Ver R7-9 no relatorio da Rodada 7.
if (!RateLimiter::isAllowed('order', 5, 60)) {
    svo_json(429, ['ok' => false, 'error' => 'rate_limited', 'message' => 'Muitas requisições. Tente novamente em 1 minuto.']);
}

// ✅ Check idempotency (previne double-submit)
if (check_idempotency() === false) {
    exit; // Already handled (cached response sent)
}

function svo_json(int $status, array $payload): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

// ✅ svo_json with idempotency recording
function svo_json_idempotent(int $status, array $payload, ?string $idempotencyKey = null): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    // ✅ Record response for idempotency if key provided
    if ($idempotencyKey !== null && IdempotencyManager::isValidKey($idempotencyKey)) {
        record_idempotent_response($idempotencyKey, $payload, $status);
    }

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

    $customer = is_array($order['customer'] ?? null) ? $order['customer'] : [];
    $entry = [
        'id' => $order['order_number'] ?? '',
        'timestamp' => $order['created_at'] ?? date('c'),
        'cliente' => [
            'nome' => $customer['name'] ?? '',
            'email' => $customer['email'] ?? '',
            'telefone' => $customer['phone'] ?? '',
            'endereco' => $customer['street_name'] ?? $customer['address'] ?? '',
            'numero' => $customer['street_number'] ?? '',
            'complemento' => $customer['complement'] ?? '',
            'bairro' => $customer['neighborhood'] ?? '',
            'cidade' => $customer['city'] ?? '',
            'estado' => $customer['state'] ?? '',
            'cpf' => $customer['cpf'] ?? '',
            'cep' => $customer['cep'] ?? '',
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
    $allowed = ['pix', 'boleto', 'whatsapp', 'transferencia', 'mercado_pago', 'infinitepay'];
    return in_array($normalized, $allowed, true) ? $normalized : 'pix';
}

function svo_payment_label(string $method): string
{
    return match ($method) {
        'boleto' => 'Boleto bancario',
        'whatsapp' => 'WhatsApp',
        'transferencia' => 'Transferencia bancaria',
        'mercado_pago' => 'Mercado Pago',
        'infinitepay' => 'InfinitePay',
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
        'infinitepay' => 'Voce sera redirecionado para o checkout seguro da InfinitePay.',
        default => 'Pagamento via PIX com confirmacao apos validacao do pedido.',
    };
}

function svo_validate_cpf(string $cpf): bool
{
    $digits = preg_replace('/\D+/', '', $cpf) ?? '';
    if (strlen($digits) !== 11 || preg_match('/^(\d)\1{10}$/', $digits) === 1) {
        return false;
    }
    for ($position = 9; $position <= 10; $position++) {
        $sum = 0;
        for ($index = 0; $index < $position; $index++) {
            $sum += ((int)$digits[$index]) * (($position + 1) - $index);
        }
        $digit = ($sum * 10) % 11;
        if ($digit === 10) {
            $digit = 0;
        }
        if ($digit !== (int)$digits[$position]) {
            return false;
        }
    }
    return true;
}

function svo_validate_cnpj(string $cnpj): bool
{
    $digits = preg_replace('/\D+/', '', $cnpj) ?? '';
    if (strlen($digits) !== 14 || preg_match('/^(\d)\1{13}$/', $digits) === 1) {
        return false;
    }

    $length = 12;
    $numbers = substr($digits, 0, $length);
    $sum = 0;
    $pos = $length - 7;
    for ($i = $length; $i >= 1; $i--) {
        $sum += (int)$numbers[$length - $i] * $pos--;
        if ($pos < 2) {
            $pos = 9;
        }
    }
    $result = $sum % 11 < 2 ? 0 : 11 - ($sum % 11);
    if ($result !== (int)$digits[12]) {
        return false;
    }

    $length = 13;
    $numbers = substr($digits, 0, $length);
    $sum = 0;
    $pos = $length - 7;
    for ($i = $length; $i >= 1; $i--) {
        $sum += (int)$numbers[$length - $i] * $pos--;
        if ($pos < 2) {
            $pos = 9;
        }
    }
    $result = $sum % 11 < 2 ? 0 : 11 - ($sum % 11);
    return $result === (int)$digits[13];
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
$streetName = trim((string)($body['street_name'] ?? $address));
$streetNumber = trim((string)($body['street_number'] ?? $body['numero'] ?? ''));
$complement = trim((string)($body['complement'] ?? $body['complemento'] ?? ''));
$neighborhood = trim((string)($body['neighborhood'] ?? $body['bairro'] ?? ''));
$city = trim((string)($body['city'] ?? ''));
$state = strtoupper(trim((string)($body['state'] ?? $body['estado'] ?? '')));
$cpf = preg_replace('/\D+/', '', (string)($body['cpf'] ?? ''));
$documentType = strtolower(trim((string)($body['document_type'] ?? '')));
$companyLegalName = trim((string)($body['company_legal_name'] ?? ''));
$companyTradeName = trim((string)($body['company_trade_name'] ?? ''));
$deviceId = trim((string)($body['device_id'] ?? ''));
$customerRegistrationDate = trim((string)($body['customer_registration_date'] ?? ''));
$customerId = trim((string)($body['customer_id'] ?? ''));
$notes = trim((string)($body['notes'] ?? ''));
$paymentMethod = svo_payment_method((string)($body['payment_method'] ?? 'pix'));
$shippingTotal = max(0.0, (float)($body['shipping_total'] ?? 0));
$shippingLabel = trim((string)($body['shipping_label'] ?? ''));
$shippingService = trim((string)($body['shipping_service'] ?? ''));
$shippingCep = preg_replace('/\D+/', '', (string)($body['shipping_cep'] ?? $cep));
$items = is_array($body['items'] ?? null) ? $body['items'] : [];

if (strlen($name) > 120 || strlen($email) > 160 || strlen($phone) > 40 || strlen($address) > 300 || strlen($streetName) > 300 || strlen($streetNumber) > 30 || strlen($complement) > 120 || strlen($neighborhood) > 120 || strlen($city) > 120 || strlen($state) > 2 || strlen($notes) > 1000 || strlen($cpf) > 14 || strlen($companyLegalName) > 180 || strlen($companyTradeName) > 180 || strlen($deviceId) > 255 || strlen($customerRegistrationDate) > 60 || strlen($customerId) > 120) {
    svo_json(422, ['ok' => false, 'error' => 'field_too_long']);
}

$validDocument = false;
if ($documentType === 'cnpj' || strlen($cpf) === 14) {
    $validDocument = svo_validate_cnpj($cpf);
    $documentType = 'cnpj';
} else {
    $validDocument = svo_validate_cpf($cpf);
    $documentType = 'cpf';
}

if (
    $name === ''
    || !filter_var($email, FILTER_VALIDATE_EMAIL)
    || $phone === ''
    || strlen($cep) !== 8
    || $streetName === ''
    || $streetNumber === ''
    || $neighborhood === ''
    || $city === ''
    || strlen($state) !== 2
    || !$validDocument
    || !$items
) {
    svo_json(422, [
        'ok' => false,
        'error' => 'customer_fields_invalid',
        'message' => 'Preencha CPF/CNPJ e endereco completo para faturamento.',
    ]);
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
// Agrega SKUs duplicados antes de comparar com o estoque para impedir que
// duas linhas do mesmo item ultrapassem o saldo real individualmente.
$stockMap = svo_stock_map();
$requestedBySku = [];
$itemNameBySku = [];
foreach ($cleanItems as $ci) {
    $skuKey = trim((string)($ci['sku'] ?? ''));
    if ($skuKey === '') {
        continue;
    }
    $requestedBySku[$skuKey] = ($requestedBySku[$skuKey] ?? 0) + max(1, (int)($ci['quantity'] ?? 1));
    $itemNameBySku[$skuKey] = (string)($ci['name'] ?? $skuKey);
}

$stockIssues = [];
foreach ($requestedBySku as $sku => $requestedQty) {
    if (!array_key_exists($sku, $stockMap)) {
        continue;
    }
    $available = max(0, (int)$stockMap[$sku]);
    if ($available <= 0 || $requestedQty > $available) {
        $stockIssues[] = [
            'sku' => $sku,
            'name' => $itemNameBySku[$sku] ?? $sku,
            'requested' => $requestedQty,
            'available' => $available,
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

$isFirstPurchaseOnline = true;
$lastPurchase = '';
try {
    require_once dirname(__DIR__, 2) . '/includes/pdo-database.php';
    require_once dirname(__DIR__, 2) . '/includes/account-schema.php';
    sv_account_ensure_schema();

    $pdo = sv_pdo();
    $previousOrderStmt = $pdo->prepare(
        'SELECT created_at FROM orders WHERE email = :email ORDER BY created_at DESC LIMIT 1'
    );
    $previousOrderStmt->execute([':email' => $email]);
    $previousOrderCreatedAt = $previousOrderStmt->fetchColumn() ?: null;
    $isFirstPurchaseOnline = empty($previousOrderCreatedAt);
    $lastPurchase = !$isFirstPurchaseOnline && is_string($previousOrderCreatedAt) ? $previousOrderCreatedAt : '';
} catch (Throwable $e) {
    error_log('[OrderCreate] Previous order lookup failed: ' . $e->getMessage());
}

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
        'cpf' => $cpf,
        'document_type' => $documentType,
        'legal_name' => $companyLegalName,
        'trade_name' => $companyTradeName,
        'registration_date' => $customerRegistrationDate !== '' ? $customerRegistrationDate : date('c'),
        'last_purchase' => $lastPurchase,
        'is_first_purchase_online' => $isFirstPurchaseOnline,
        'customer_id' => $customerId,
        'street_name' => $streetName,
        'street_number' => $streetNumber,
        'complement' => $complement,
        'neighborhood' => $neighborhood,
        'city' => $city,
        'state' => $state,
    ],
    'device_id' => $deviceId,
    'items' => $cleanItems,
    'items_total' => round($itemsTotal, 2),
    'shipping_total' => round($shippingTotal, 2),
    'shipping_label' => $shippingLabel,
    'shipping_service' => $shippingService,
    'shipping_cep' => $shippingCep,
    'total' => round($grandTotal, 2),
    'payment_method' => $paymentMethod,
    'payment_label' => svo_payment_label($paymentMethod),
    'statement_descriptor' => 'SHOPVIVALIZ',
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

    $stmt = $pdo->prepare(
        'INSERT INTO orders (user_id, order_number, olist_order_id, email, order_total, order_status, payment_method, items_json, created_at)
         VALUES (:user_id, :order_number, :olist_order_id, :email, :total, :status, :payment_method, :items_json, NOW())'
    );
    $stmt->execute([
        ':user_id' => $sessionUserId,
        ':order_number' => $orderNumber,
        ':olist_order_id' => null,
        ':email' => $email,
        ':total' => round($grandTotal, 2),
        ':status' => 'aguardando_pagamento',
        ':payment_method' => $paymentMethod,
        ':items_json' => json_encode($cleanItems, JSON_UNESCAPED_UNICODE),
    ]);
} catch (Throwable $e) {
    error_log('[OrderCreate] MySQL orders mirror failed: ' . $e->getMessage());
}

$idempotencyKey = $_SERVER['HTTP_IDEMPOTENCY_KEY'] ?? $_POST['idempotency_key'] ?? null;
$successPayload = [
    'ok' => true,
    'order_number' => $orderNumber,
    'payment_session_token' => $paymentSessionToken,
    'status' => 'pending_confirmation',
    'payment_method' => $paymentMethod,
    'payment_label' => $record['payment_label'],
    'message' => 'Pedido registrado para confirmacao manual de frete e pagamento.',
    'payment_instructions' => svo_payment_instructions($paymentMethod),
    'storage' => str_contains($dir, 'shopvivaliz-orders') ? 'fallback_temp' : 'storage_orders',
    'subtotal' => round($itemsTotal, 2),
    'shipping_total' => round($shippingTotal, 2),
    'shipping_label' => $shippingLabel,
    'total' => round($grandTotal, 2),
];

svo_json_idempotent(200, $successPayload, $idempotencyKey);

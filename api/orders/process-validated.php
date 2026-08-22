<?php
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once dirname(__DIR__, 2) . '/includes/order-request-context.php';
require_once dirname(__DIR__, 2) . '/includes/order-idempotency.php';
require_once dirname(__DIR__, 2) . '/includes/mercadopago-gateway.php';
require_once dirname(__DIR__, 2) . '/api/emails/send-order-notification.php';
require_once dirname(__DIR__, 2) . '/includes/tiny-order-push.php';

function svop_json(int $status, array $payload): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function svop_root(): string
{
    return dirname(__DIR__, 2);
}

function svop_order_dir(): string
{
    $preferred = svop_root() . '/storage/orders';
    if ((is_dir($preferred) || @mkdir($preferred, 0755, true)) && is_writable($preferred)) {
        return $preferred;
    }

    $fallback = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'shopvivaliz-orders';
    if ((is_dir($fallback) || @mkdir($fallback, 0755, true)) && is_writable($fallback)) {
        return $fallback;
    }

    return '';
}

function svop_payment_method(string $value): string
{
    $normalized = strtolower(trim($value));
    $allowed = ['pix', 'boleto', 'whatsapp', 'transferencia', 'mercado_pago', 'infinitepay'];
    return in_array($normalized, $allowed, true) ? $normalized : '';
}

function svop_payment_label(string $method): string
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

function svop_payment_instructions(string $method): string
{
    return match ($method) {
        'boleto' => 'Boleto emitido pelo Mercado Pago com linha digitavel e link seguro.',
        'whatsapp' => 'Pagamento e frete serao alinhados pelo atendimento no WhatsApp.',
        'transferencia' => 'Dados bancarios serao enviados pela equipe apos confirmacao do frete.',
        'mercado_pago' => 'Pagamento processado no ambiente seguro do Mercado Pago.',
        'infinitepay' => 'Voce sera redirecionado para o checkout seguro da InfinitePay.',
        default => 'PIX',
    };
}

function svop_append_log(array $order): void
{
    $dir = svop_root() . '/logs';
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }

    $entry = [
        'id' => $order['order_number'] ?? '',
        'timestamp' => $order['created_at'] ?? date('c'),
        'cliente' => [
            'nome' => $order['customer']['name'] ?? '',
            'email' => $order['customer']['email'] ?? '',
            'telefone' => $order['customer']['phone'] ?? '',
            'endereco' => $order['customer']['address'] ?? '',
            'cep' => $order['customer']['cep'] ?? '',
        ],
        'items' => $order['items'] ?? [],
        'payment_method' => $order['payment_method'] ?? 'pix',
        'status' => 'pendente_atendimento',
        'source' => 'checkout_site_api',
        'local_storage_role' => 'pre_payment_draft_mirror',
        'erp_authority' => 'tiny_v3_after_payment_approval',
        'buy_together_discount' => round((float)($order['buy_together_discount'] ?? 0), 2),
        'coupon_discount' => round((float)($order['coupon_discount'] ?? 0), 2),
        'shipping_total' => round((float)($order['shipping_total'] ?? 0), 2),
        'shipping_label' => (string)($order['shipping_label'] ?? ''),
        'tiny_order_id' => (string)($order['tiny_order_id'] ?? ''),
        'tiny_push' => (string)($order['tiny_push'] ?? ''),
        'total' => round((float)($order['total'] ?? 0), 2),
    ];

    @file_put_contents(
        $dir . '/pedidos.jsonl',
        json_encode($entry, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL,
        FILE_APPEND | LOCK_EX
    );
}

function svop_load_runtime_secrets(): void
{
    static $loaded = false;
    if ($loaded) {
        return;
    }
    $loaded = true;

    $path = svop_root() . '/config/runtime-secrets.php';
    if (!is_file($path) || !is_readable($path)) {
        return;
    }

    $secrets = require $path;
    if (!is_array($secrets)) {
        return;
    }

    foreach ($secrets as $key => $value) {
        if (!is_string($key) || $key === '' || getenv($key) !== false) {
            continue;
        }
        $stringValue = is_scalar($value) ? (string)$value : '';
        putenv($key . '=' . $stringValue);
        $_ENV[$key] = $stringValue;
        $_SERVER[$key] = $stringValue;
    }
}

$body = svorc_body();
$items = svorc_items();
$idempotencyKey = svoi_key($body, $items);
if ($body === [] || $items === []) {
    svop_json(500, ['ok' => false, 'error' => 'validated_context_missing']);
}

$name = trim((string)($body['customer_name'] ?? ''));
$email = trim((string)($body['customer_email'] ?? ''));
$phone = trim((string)($body['customer_phone'] ?? ''));
$cep = preg_replace('/\D+/', '', (string)($body['cep'] ?? ''));
$address = trim((string)($body['address'] ?? ''));
$cpf = preg_replace('/\D+/', '', (string)($body['cpf'] ?? ''));
$documentType = strtolower(trim((string)($body['document_type'] ?? '')));
$companyLegalName = trim((string)($body['company_legal_name'] ?? ''));
$companyTradeName = trim((string)($body['company_trade_name'] ?? ''));
$customerRegistrationDate = trim((string)($body['customer_registration_date'] ?? ''));
$customerId = trim((string)($body['customer_id'] ?? ''));
$streetName = trim((string)($body['street_name'] ?? $address));
$streetNumber = trim((string)($body['street_number'] ?? $body['numero'] ?? ''));
$complement = trim((string)($body['complement'] ?? $body['complemento'] ?? ''));
$neighborhood = trim((string)($body['neighborhood'] ?? $body['bairro'] ?? ''));
$city = trim((string)($body['city'] ?? $body['cidade'] ?? ''));
$state = strtoupper(trim((string)($body['state'] ?? $body['estado'] ?? $body['uf'] ?? '')));
$notes = trim((string)($body['notes'] ?? ''));
$paymentMethod = svop_payment_method((string)($body['payment_method'] ?? 'pix'));
if ($paymentMethod === '') {
    svoi_release($idempotencyKey);
    svop_json(422, ['ok' => false, 'error' => 'payment_method_invalid']);
}
$deviceId = trim((string)($body['device_id'] ?? ''));
$funnelClientId = trim((string)($body['funnel_client_id'] ?? ''));
$gclid = trim((string)($body['gclid'] ?? ''));
$gbraid = trim((string)($body['gbraid'] ?? ''));
$wbraid = trim((string)($body['wbraid'] ?? ''));
$dclid = trim((string)($body['dclid'] ?? ''));
$utmSource = trim((string)($body['utm_source'] ?? ''));
$utmMedium = trim((string)($body['utm_medium'] ?? ''));
$utmCampaign = trim((string)($body['utm_campaign'] ?? ''));
$utmContent = trim((string)($body['utm_content'] ?? ''));

if (strlen($name) > 120 || strlen($email) > 160 || strlen($phone) > 40 || strlen($address) > 300 || strlen($streetName) > 300 || strlen($streetNumber) > 30 || strlen($complement) > 120 || strlen($neighborhood) > 120 || strlen($city) > 120 || strlen($state) > 2 || strlen($notes) > 1000 || strlen($deviceId) > 255 || strlen($cpf) > 14 || strlen($companyLegalName) > 180 || strlen($companyTradeName) > 180 || strlen($customerRegistrationDate) > 60 || strlen($customerId) > 120 || strlen($funnelClientId) > 128 || strlen($gclid) > 255 || strlen($gbraid) > 255 || strlen($wbraid) > 255 || strlen($dclid) > 255 || strlen($utmSource) > 255 || strlen($utmMedium) > 255 || strlen($utmCampaign) > 255 || strlen($utmContent) > 255) {
    svoi_release($idempotencyKey);
    svop_json(422, ['ok' => false, 'error' => 'field_too_long']);
}

$validDocument = false;
if ($documentType === 'cnpj' || strlen($cpf) === 14) {
    $validDocument = svmp_validate_cnpj($cpf);
    $documentType = 'cnpj';
} else {
    $validDocument = svmp_validate_cpf($cpf);
    $documentType = 'cpf';
}

if (
    $name === ''
    || !filter_var($email, FILTER_VALIDATE_EMAIL)
    || $phone === ''
    || strlen($cep) !== 8
    || $address === ''
    || $streetName === ''
    || $streetNumber === ''
    || $neighborhood === ''
    || $city === ''
    || strlen($state) !== 2
    || !$validDocument
) {
    svoi_release($idempotencyKey);
    svop_json(422, [
        'ok' => false,
        'error' => 'customer_fields_invalid',
        'message' => 'Preencha CPF/CNPJ e endereco completo para finalizar o pedido.',
    ]);
}

$shippingTotal = round(max(0.0, (float)($body['shipping_total'] ?? 0)), 2);
$shippingLabel = trim((string)($body['shipping_label'] ?? ''));
$shippingService = trim((string)($body['shipping_service'] ?? ''));
$shippingCep = preg_replace('/\D+/', '', (string)($body['shipping_cep'] ?? $cep));

$itemsTotal = 0.0;
$cleanItems = [];
$authoritativeItems = function_exists('svorc_items') ? svorc_items() : [];
$sourceItems = $authoritativeItems !== [] ? $authoritativeItems : $items;
foreach ($sourceItems as $item) {
    $price = round((float)($item['price'] ?? 0), 2);
    $quantity = (int)($item['quantity'] ?? 0);
    $itemsTotal += $price * $quantity;
    $cleanItems[] = [
        'sku' => (string)($item['sku'] ?? ''),
        'name' => (string)($item['name'] ?? ''),
        'quantity' => $quantity,
        'price' => $price,
        'olist_product_id' => (string)($item['olist_product_id'] ?? ''),
    ];
}
$itemsTotal = round($itemsTotal, 2);

// A oferta foi validada em create-validated.php com os itens autoritativos.
// O desconto é um ajuste separado para preservar o preço unitário real dos
// produtos no ERP, histórico e conciliação. Apenas um par (uma unidade de cada
// SKU) recebe os 3%, mesmo que o cliente tenha quantidades adicionais.
$buyTogether = is_array($body['buy_together'] ?? null) ? $body['buy_together'] : [];
$buyTogetherActive = ($buyTogether['active'] ?? false) === true;
$buyTogetherDiscount = $buyTogetherActive
    ? round(min((float)($buyTogether['amount'] ?? 0), $itemsTotal), 2)
    : 0.0;
$buyTogetherSkus = $buyTogetherActive && is_array($buyTogether['skus'] ?? null)
    ? array_values(array_map('strval', $buyTogether['skus']))
    : [];

$coupon = is_array($body['coupon'] ?? null) ? $body['coupon'] : null;
$couponCode = $coupon !== null ? (string)($coupon['code'] ?? '') : '';
$afterBundleSubtotal = max(0.0, round($itemsTotal - $buyTogetherDiscount, 2));
$couponDiscount = $coupon !== null ? round(min((float)($coupon['amount'] ?? 0), $afterBundleSubtotal), 2) : 0.0;

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
    error_log('[OrderValidated] Previous order lookup failed: ' . $e->getMessage());
}

$orderNumber = 'SV' . date('YmdHis') . random_int(100, 999);
$paymentSessionToken = in_array($paymentMethod, ['boleto', 'mercado_pago', 'infinitepay'], true)
    ? bin2hex(random_bytes(32))
    : '';
$record = [
    'order_number' => $orderNumber,
    'device_id' => $deviceId,
    'status' => 'pending_confirmation',
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
    'items' => $cleanItems,
    'items_total' => $itemsTotal,
    'buy_together_active' => $buyTogetherActive,
    'buy_together_percent' => $buyTogetherActive ? 3.0 : 0.0,
    'buy_together_skus' => $buyTogetherSkus,
    'buy_together_discount' => $buyTogetherDiscount,
    'coupon_code' => $couponCode,
    'coupon_discount' => $couponDiscount,
    'shipping_total' => $shippingTotal,
    'shipping_label' => $shippingLabel,
    'shipping_service' => $shippingService,
    'shipping_cep' => $shippingCep,
    'total' => round($itemsTotal - $buyTogetherDiscount - $couponDiscount + $shippingTotal, 2),
    'payment_method' => $paymentMethod,
    'payment_label' => svop_payment_label($paymentMethod),
    'statement_descriptor' => 'SHOPVIVALIZ',
    'notes' => $notes,
    'created_at' => date('c'),
    'source' => 'site_checkout_validated',
    'idempotency_key_hash' => hash('sha256', $idempotencyKey),
    'payment_session_hash' => $paymentSessionToken !== '' ? hash('sha256', $paymentSessionToken) : '',
    'funnel_client_id' => $funnelClientId,
    'gclid' => $gclid,
    'gbraid' => $gbraid,
    'wbraid' => $wbraid,
    'dclid' => $dclid,
    'utm' => [
        'source' => $utmSource,
        'medium' => $utmMedium,
        'campaign' => $utmCampaign,
        'content' => $utmContent,
    ],
];

$dir = svop_order_dir();
if ($dir === '') {
    svoi_release($idempotencyKey);
    svop_json(500, ['ok' => false, 'error' => 'order_storage_unavailable']);
}

$path = $dir . '/' . $orderNumber . '.json';
if (file_put_contents($path, json_encode($record, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX) === false) {
    svoi_release($idempotencyKey);
    svop_json(500, ['ok' => false, 'error' => 'order_write_failed']);
}

try {
    require_once dirname(__DIR__, 2) . '/includes/pdo-database.php';
    require_once dirname(__DIR__, 2) . '/includes/account-schema.php';
    sv_account_ensure_schema();

    $sessionUserId = isset($_SESSION['user_id']) && is_numeric($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;

    $pdo = sv_pdo();
    $stmt = $pdo->prepare(
        'INSERT INTO orders (user_id, order_number, olist_order_id, email, order_total, order_status, payment_method, items_json, created_at)
         VALUES (:user_id, :order_number, :olist_order_id, :email, :total, :status, :payment_method, :items_json, NOW())'
    );
    $stmt->execute([
        ':user_id' => $sessionUserId,
        ':order_number' => $orderNumber,
        ':olist_order_id' => null,
        ':email' => $email,
        ':total' => $record['total'],
        ':status' => 'aguardando_pagamento',
        ':payment_method' => $paymentMethod,
        ':items_json' => json_encode($cleanItems, JSON_UNESCAPED_UNICODE),
    ]);
} catch (Throwable $e) {
    error_log('[OrderValidated] MySQL orders mirror failed: ' . $e->getMessage());
}

file_put_contents($path, json_encode($record, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);
svop_append_log($record);

try {
    $emailSent = svem_send_order_email($record, 'order_created');
    $record['confirmation_email_sent'] = $emailSent;
    svem_notify_admin_order_created($record);
} catch (Throwable $e) {
    error_log('[OrderValidated] Email send error: ' . $e->getMessage());
    $record['confirmation_email_sent'] = false;
}

$response = [
    'ok' => true,
    'order_number' => $orderNumber,
    'status' => 'pending_confirmation',
    'payment_method' => $paymentMethod,
    'payment_label' => $record['payment_label'],
    'message' => 'Pedido registrado com preço, estoque, promoção e frete revalidados no servidor.',
    'payment_instructions' => svop_payment_instructions($paymentMethod),
    'storage' => str_contains($dir, 'shopvivaliz-orders') ? 'fallback_temp' : 'storage_orders',
    'local_storage_role' => 'pre_payment_draft_mirror',
    'erp_authority' => 'tiny_v3_after_payment_approval',
    'subtotal' => $itemsTotal,
    'buy_together_active' => $buyTogetherActive,
    'buy_together_percent' => $buyTogetherActive ? 3.0 : 0.0,
    'buy_together_discount' => $buyTogetherDiscount,
    'coupon_code' => $couponCode,
    'coupon_discount' => $couponDiscount,
    'shipping_total' => $shippingTotal,
    'shipping_label' => $shippingLabel,
    'total' => $record['total'],
];
if ($paymentSessionToken !== '') {
    $response['payment_session_token'] = $paymentSessionToken;
}
svop_json(200, $response);

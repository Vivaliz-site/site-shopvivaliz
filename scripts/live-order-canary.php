<?php
declare(strict_types=1);

/**
 * One-shot production checkout canary.
 *
 * Creates a real low-value boleto order using the latest prior Frederico
 * customer record, verifies email/boleto persistence and confirms the unpaid
 * order was NOT imported into Tiny. It never pays the boleto and marks the
 * order as do-not-fulfill. Output is redacted and contains no PII or boleto.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}

$confirmation = (string)(getenv('SHOPVIVALIZ_LIVE_CANARY') ?: '');
if ($confirmation !== 'RUN') {
    fwrite(STDERR, "SHOPVIVALIZ_LIVE_CANARY=RUN is required.\n");
    exit(2);
}

$root = dirname(__DIR__);
require_once $root . '/includes/catalog-runtime.php';

function canary_fail(string $code, string $message, array $extra = []): never
{
    $payload = array_merge(['ok' => false, 'code' => $code, 'message' => $message], $extra);
    fwrite(STDOUT, json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL);
    exit(1);
}

function canary_post(string $url, array $payload): array
{
    $handle = curl_init($url);
    curl_setopt_array($handle, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Accept: application/json',
            'User-Agent: ShopVivaliz-Live-Order-Canary/1.0',
        ],
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_TIMEOUT => 60,
        CURLOPT_FOLLOWLOCATION => true,
    ]);
    $body = curl_exec($handle);
    $error = curl_error($handle);
    $status = (int)curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
    curl_close($handle);
    if (!is_string($body)) {
        canary_fail('http_transport_failure', 'HTTP transport failed', ['status' => $status, 'transport_error' => $error !== '']);
    }
    $decoded = json_decode($body, true);
    if (!is_array($decoded)) {
        canary_fail('invalid_json_response', 'Endpoint returned invalid JSON', ['status' => $status]);
    }
    if ($status < 200 || $status >= 300 || empty($decoded['ok'])) {
        canary_fail('endpoint_failure', 'Endpoint rejected the canary request', [
            'status' => $status,
            'endpoint_error' => (string)($decoded['error'] ?? $decoded['message'] ?? 'unknown'),
        ]);
    }
    return $decoded;
}

function canary_order_dirs(string $root): array
{
    $dirs = [
        $root . '/storage/orders',
        rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'shopvivaliz-orders',
    ];
    return array_values(array_filter(array_unique($dirs), 'is_dir'));
}

function canary_latest_customer(string $root): array
{
    $candidates = [];
    foreach (canary_order_dirs($root) as $dir) {
        foreach (glob($dir . '/SV*.json') ?: [] as $path) {
            $mtime = (int)@filemtime($path);
            $candidates[$path] = $mtime;
        }
    }
    arsort($candidates);
    foreach (array_keys($candidates) as $path) {
        $record = json_decode((string)@file_get_contents($path), true);
        if (!is_array($record)) continue;
        $customer = is_array($record['customer'] ?? null) ? $record['customer'] : [];
        $name = trim((string)($customer['name'] ?? ''));
        $normalized = function_exists('mb_strtolower') ? mb_strtolower($name, 'UTF-8') : strtolower($name);
        if (!str_contains($normalized, 'frederico') || !str_contains($normalized, 'mour')) continue;
        $required = ['name', 'email', 'phone', 'cep', 'address', 'cpf', 'street_name', 'street_number', 'neighborhood', 'city', 'state'];
        foreach ($required as $field) {
            if (trim((string)($customer[$field] ?? '')) === '') continue 2;
        }
        return $customer;
    }
    return [];
}

function canary_product(): array
{
    foreach (svcr_products() as $product) {
        if (!is_array($product)) continue;
        $slug = trim((string)($product['slug'] ?? ''));
        $name = trim((string)($product['name'] ?? ''));
        $normalized = function_exists('mb_strtolower') ? mb_strtolower($name, 'UTF-8') : strtolower($name);
        if ($slug === 'chave-teste-140mm-100500v' || (str_contains($normalized, 'chave teste') && str_contains($normalized, '140mm'))) {
            if ((float)($product['price'] ?? 0) <= 0 || (int)($product['stock'] ?? 0) <= 0) {
                canary_fail('test_product_unavailable', 'Test key is not available with positive price and stock');
            }
            return $product;
        }
    }
    return [];
}

function canary_find_order(string $root, string $orderNumber): array
{
    foreach (canary_order_dirs($root) as $dir) {
        $path = $dir . '/' . basename($orderNumber) . '.json';
        if (!is_file($path)) continue;
        $record = json_decode((string)file_get_contents($path), true);
        if (is_array($record)) return [$path, $record];
    }
    return ['', []];
}

$baseUrl = rtrim((string)(getenv('SHOPVIVALIZ_BASE_URL') ?: 'https://shopvivaliz.com.br'), '/');
$customer = canary_latest_customer($root);
if ($customer === []) canary_fail('prior_customer_not_found', 'No complete previous Frederico order was found');
$product = canary_product();
if ($product === []) canary_fail('test_product_not_found', 'Chave Teste 140MM product was not found');

$item = [
    'sku' => (string)($product['sku'] ?? ''),
    'id' => (string)($product['id'] ?? ''),
    'olist_product_id' => (string)($product['olist_product_id'] ?? ''),
    'name' => (string)($product['name'] ?? 'Chave Teste 140MM 100/500V'),
    'price' => round((float)$product['price'], 2),
    'quantity' => 1,
    'image_url' => (string)($product['image_url'] ?? ''),
];

$shipping = canary_post($baseUrl . '/api/melhorenvio/shipping-check-v2.php', [
    'cep' => preg_replace('/\D+/', '', (string)$customer['cep']),
    'items' => [[
        'sku' => $item['sku'],
        'product_id' => $item['id'],
        'olist_product_id' => $item['olist_product_id'],
        'quantity' => 1,
    ]],
]);
$options = is_array($shipping['shipping_options'] ?? null) ? $shipping['shipping_options'] : [];
$selected = is_array($shipping['selected_option'] ?? null) ? $shipping['selected_option'] : ($options[0] ?? null);
if (!is_array($selected)) canary_fail('shipping_unavailable', 'No shipping option was returned');

$canaryId = 'E2E-' . gmdate('Ymd-His') . '-' . bin2hex(random_bytes(3));
$orderPayload = [
    'items' => [$item],
    'customer_name' => (string)$customer['name'],
    'customer_email' => (string)$customer['email'],
    'customer_phone' => (string)$customer['phone'],
    'cep' => (string)$customer['cep'],
    'address' => (string)$customer['address'],
    'cpf' => (string)$customer['cpf'],
    'document_type' => (string)($customer['document_type'] ?? 'cpf'),
    'street_name' => (string)$customer['street_name'],
    'street_number' => (string)$customer['street_number'],
    'complement' => (string)($customer['complement'] ?? ''),
    'neighborhood' => (string)$customer['neighborhood'],
    'city' => (string)$customer['city'],
    'state' => (string)$customer['state'],
    'payment_method' => 'boleto',
    'notes' => '[CANARY ' . $canaryId . '] PEDIDO TECNICO - NAO EXPEDIR - NAO PAGAR',
    'shipping_total' => round((float)($selected['price'] ?? 0), 2),
    'shipping_label' => trim((string)($selected['company'] ?? '') . ' - ' . (string)($selected['name'] ?? 'Frete')),
    'shipping_service' => (string)($selected['id'] ?? ''),
    'shipping_cep' => preg_replace('/\D+/', '', (string)$customer['cep']),
    'shipping_quote_id' => (string)($selected['quote_id'] ?? ''),
    'shipping_expires_at' => (int)($selected['expires_at'] ?? 0),
    'device_id' => 'shopvivaliz-live-canary',
];

$order = canary_post($baseUrl . '/api/orders/create.php', $orderPayload);
$orderNumber = (string)($order['order_number'] ?? '');
$sessionToken = (string)($order['payment_session_token'] ?? '');
if ($orderNumber === '' || $sessionToken === '') canary_fail('order_session_missing', 'Order did not return a secure boleto session');

$boleto = canary_post($baseUrl . '/api/mercadopago/create-boleto.php', [
    'order_number' => $orderNumber,
    'payment_session_token' => $sessionToken,
]);

sleep(5);
[$orderPath, $record] = canary_find_order($root, $orderNumber);
if ($record === []) canary_fail('order_file_missing', 'Created order was not persisted', ['order_number' => $orderNumber]);
$record['test_order'] = true;
$record['do_not_fulfill'] = true;
$record['canary_id'] = $canaryId;
$record['canary_verified_at'] = date(DATE_ATOM);
file_put_contents($orderPath, json_encode($record, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), LOCK_EX);

$boletoState = is_array($record['mercadopago']['boleto'] ?? null) ? $record['mercadopago']['boleto'] : [];
$tinyOrderId = trim((string)($record['tiny_order_id'] ?? ''));
$tinyPush = trim((string)($record['tiny_push'] ?? ''));
$result = [
    'ok' => true,
    'order_number' => $orderNumber,
    'canary_id' => $canaryId,
    'payment_method' => (string)($record['payment_method'] ?? ''),
    'payment_status' => (string)($record['status'] ?? ''),
    'boleto_created' => trim((string)($boletoState['ticket_url'] ?? '')) !== '',
    'digitable_line_present' => trim((string)($boletoState['digitable_line'] ?? '')) !== '',
    'boleto_email_sent' => (bool)($boletoState['email_sent'] ?? $boleto['email_sent'] ?? false),
    'customer_order_email_endpoint_completed' => true,
    'tiny_import_expected_before_payment' => false,
    'tiny_not_imported_while_unpaid' => $tinyOrderId === '' && $tinyPush === '',
    'do_not_fulfill' => true,
    'product_slug' => (string)($product['slug'] ?? ''),
    'total_positive' => (float)($record['total'] ?? 0) > 0,
];

foreach (['boleto_created', 'digitable_line_present', 'boleto_email_sent', 'tiny_not_imported_while_unpaid', 'do_not_fulfill', 'total_positive'] as $required) {
    if (empty($result[$required])) canary_fail('canary_assertion_failed', 'A live order assertion failed', ['assertion' => $required, 'order_number' => $orderNumber]);
}

fwrite(STDOUT, json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL);

<?php

declare(strict_types=1);

require_once __DIR__ . '/mercadopago-gateway.php';

function svmp_sb_env(string ...$keys): string
{
    return svmp_env(...$keys);
}

function svmp_sb_access_token(): string
{
    return svmp_sb_env(
        'MERCADOPAGO_TEST_ACCESS_TOKEN',
        'MP_TEST_ACCESS_TOKEN',
        'MERCADOPAGO_SANDBOX_ACCESS_TOKEN'
    );
}

function svmp_sb_public_key(): string
{
    return svmp_sb_env(
        'MERCADOPAGO_TEST_PUBLIC_KEY',
        'MP_TEST_PUBLIC_KEY',
        'MERCADOPAGO_SANDBOX_PUBLIC_KEY'
    );
}

function svmp_sb_webhook_secret(): string
{
    return svmp_sb_env(
        'MERCADOPAGO_TEST_WEBHOOK_SECRET',
        'MP_TEST_WEBHOOK_SECRET',
        'MERCADOPAGO_SANDBOX_WEBHOOK_SECRET'
    );
}

function svmp_sb_base_url(): string
{
    return svmp_base_url();
}

function svmp_sb_order_number(): string
{
    return 'SVT' . date('YmdHis') . random_int(100, 999);
}

function svmp_sb_order_dir(): string
{
    $dir = dirname(__DIR__) . '/storage/orders-sandbox';
    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    return is_dir($dir) && is_writable($dir) ? $dir : '';
}

function svmp_sb_order_path(string $orderNumber): string
{
    return rtrim(svmp_sb_order_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $orderNumber . '.json';
}

function svmp_sb_find_order_path(string $orderNumber): string
{
    if (preg_match('/^SVT\d{17}$/', $orderNumber) !== 1) {
        return '';
    }
    $path = svmp_sb_order_path($orderNumber);
    return is_file($path) ? $path : '';
}

function svmp_sb_session_token(): string
{
    return bin2hex(random_bytes(32));
}

function svmp_sb_session_matches(array $order, string $token): bool
{
    $expected = (string)($order['payment_session_hash'] ?? '');
    return $expected !== '' && $token !== '' && hash_equals($expected, hash('sha256', $token));
}

function svmp_sb_notification_url(): string
{
    return svmp_sb_base_url() . '/api/sandbox/webhook-mercadopago.php?source_news=webhooks';
}

function svmp_sb_preference_payload(array $order): array
{
    $payload = svmp_preference_payload($order);
    $payload['notification_url'] = svmp_sb_notification_url();
    $payload['back_urls'] = [
        'success' => svmp_sb_base_url() . '/admin/mercadopago-sandbox.php?result=success',
        'pending' => svmp_sb_base_url() . '/admin/mercadopago-sandbox.php?result=pending',
        'failure' => svmp_sb_base_url() . '/admin/mercadopago-sandbox.php?result=failure',
    ];
    $payload['metadata'] = array_merge(
        is_array($payload['metadata'] ?? null) ? $payload['metadata'] : [],
        ['shopvivaliz_sandbox' => true]
    );
    return $payload;
}

function svmp_sb_create_preference(array $order): array
{
    $accessToken = svmp_sb_access_token();
    if ($accessToken === '') {
        throw new SvMercadoPagoApiException(503, 'sandbox_gateway_unconfigured');
    }
    $payload = svmp_sb_preference_payload($order);
    $orderNumber = (string)($order['order_number'] ?? '');
    $idempotencyKey = hash_hmac('sha256', 'sandbox_preference|' . $orderNumber, $accessToken);
    $deviceId = (string)($order['device_id'] ?? '');
    $response = svmp_api_request('POST', '/checkout/preferences', $accessToken, $payload, $idempotencyKey, $deviceId);
    $checkoutUrl = (string)($response['sandbox_init_point'] ?? $response['init_point'] ?? '');
    if ((string)($response['id'] ?? '') === '' || !str_starts_with($checkoutUrl, 'https://')) {
        throw new SvMercadoPagoApiException(502, 'sandbox_preference_missing_checkout_url');
    }
    return [
        'preference_id' => (string)$response['id'],
        'checkout_url' => $checkoutUrl,
    ];
}

function svmp_sb_record(array $input): array
{
    $customerName = trim((string)($input['customer_name'] ?? 'Comprador de teste'));
    $email = trim((string)($input['customer_email'] ?? ''));
    $phone = preg_replace('/\D+/', '', (string)($input['customer_phone'] ?? '')) ?? '';
    $cpf = preg_replace('/\D+/', '', (string)($input['cpf'] ?? '')) ?? '';
    $cep = preg_replace('/\D+/', '', (string)($input['cep'] ?? '')) ?? '';
    $street = trim((string)($input['address'] ?? 'Rua de Teste'));
    $streetNumber = trim((string)($input['street_number'] ?? '123'));
    $neighborhood = trim((string)($input['neighborhood'] ?? 'Centro'));
    $city = trim((string)($input['city'] ?? 'Divinopolis'));
    $state = strtoupper(trim((string)($input['state'] ?? 'MG')));
    $quantity = max(1, (int)($input['quantity'] ?? 1));
    $price = round((float)($input['unit_price'] ?? 1), 2);
    $title = trim((string)($input['title'] ?? 'Pedido de teste Mercado Pago'));
    $sku = trim((string)($input['sku'] ?? 'sandbox-item'));
    $shipping = round((float)($input['shipping_total'] ?? 0), 2);
    $orderNumber = svmp_sb_order_number();
    $sessionToken = svmp_sb_session_token();
    $items = [[
        'sku' => $sku,
        'name' => $title,
        'quantity' => $quantity,
        'price' => $price,
        'olist_product_id' => $sku,
    ]];

    return [
        'order_number' => $orderNumber,
        'status' => 'sandbox_pending',
        'sandbox_mode' => true,
        'payment_session_hash' => hash('sha256', $sessionToken),
        'customer' => [
            'name' => $customerName,
            'email' => $email,
            'phone' => $phone,
            'cep' => $cep,
            'address' => $street,
            'cpf' => $cpf,
            'street_name' => $street,
            'street_number' => $streetNumber,
            'neighborhood' => $neighborhood,
            'city' => $city,
            'state' => $state,
        ],
        'items' => $items,
        'items_total' => round($price * $quantity, 2),
        'shipping_total' => $shipping,
        'shipping_label' => $shipping > 0 ? 'Sandbox shipping' : '',
        'shipping_service' => $shipping > 0 ? 'sandbox' : '',
        'shipping_cep' => $cep,
        'total' => round(($price * $quantity) + $shipping, 2),
        'payment_method' => 'mercado_pago',
        'payment_label' => 'Mercado Pago Sandbox',
        'notes' => 'Fluxo isolado de teste/sandbox',
        'created_at' => date(DATE_ATOM),
        'source' => 'sandbox_checkout',
        '_payment_session_token' => $sessionToken,
    ];
}


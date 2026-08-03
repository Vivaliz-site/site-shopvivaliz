<?php
declare(strict_types=1);

require_once __DIR__ . '/mercadopago-gateway.php';

/**
 * Payload mínimo documentado para boleto via Mercado Pago Orders API.
 *
 * A API é estrita para este meio de pagamento. Em especial, payer.address.state
 * deve ser a UF brasileira de dois caracteres. Campos não presentes no exemplo
 * oficial (incluindo headers de sessão fabricados e notification_url por order)
 * não são enviados; o webhook Orders deve permanecer configurado na integração.
 *
 * @return array<string,mixed>
 */
function svmp_boleto_orders_payload_v2(array $order): array
{
    $customer = is_array($order['customer'] ?? null) ? $order['customer'] : [];
    [$firstName, $lastName] = svmp_split_name((string)($customer['name'] ?? ''));
    $cpf = preg_replace('/\D+/', '', (string)($customer['cpf'] ?? '')) ?? '';
    $total = round((float)($order['total'] ?? 0), 2);

    if ($total <= 0 || !svmp_validate_cpf($cpf)) {
        throw new InvalidArgumentException('invalid_boleto_order');
    }

    $required = ['email', 'cep', 'street_name', 'neighborhood', 'city', 'state'];
    foreach ($required as $field) {
        if (trim((string)($customer[$field] ?? '')) === '') {
            throw new InvalidArgumentException('missing_boleto_payer_fields');
        }
    }

    $email = trim((string)$customer['email']);
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new InvalidArgumentException('invalid_boleto_email');
    }

    $state = strtoupper(trim((string)$customer['state']));
    if (preg_match('/^[A-Z]{2}$/', $state) !== 1) {
        throw new InvalidArgumentException('invalid_boleto_state');
    }

    $zipCode = preg_replace('/\D+/', '', (string)$customer['cep']) ?? '';
    if (strlen($zipCode) !== 8) {
        throw new InvalidArgumentException('invalid_boleto_zip_code');
    }

    $streetNumber = trim((string)($customer['street_number'] ?? ''));
    if ($streetNumber === '') {
        $streetNumber = 'S/N';
    }

    $orderNumber = trim((string)($order['order_number'] ?? ''));
    if (!svmp_order_number_is_valid($orderNumber)) {
        throw new InvalidArgumentException('invalid_order_number');
    }

    return [
        'type' => 'online',
        'external_reference' => $orderNumber,
        'processing_mode' => 'automatic',
        'total_amount' => svmp_money($total),
        'description' => 'Pedido ShopVivaliz ' . $orderNumber,
        'payer' => [
            'email' => $email,
            'first_name' => $firstName,
            'last_name' => $lastName,
            'identification' => [
                'type' => 'CPF',
                'number' => $cpf,
            ],
            'address' => [
                'street_name' => trim((string)$customer['street_name']),
                'street_number' => $streetNumber,
                'zip_code' => $zipCode,
                'neighborhood' => trim((string)$customer['neighborhood']),
                'state' => $state,
                'city' => trim((string)$customer['city']),
            ],
        ],
        'transactions' => [
            'payments' => [[
                'amount' => svmp_money($total),
                'payment_method' => [
                    'id' => 'boleto',
                    'type' => 'ticket',
                ],
            ]],
        ],
    ];
}

/** @return array<string,string> */
function svmp_create_boleto_orders_v2(array $order, string $accessToken): array
{
    $payload = svmp_boleto_orders_payload_v2($order);
    $orderNumber = (string)$order['order_number'];

    // The schema changed after a rejected request. Versioning keeps retries of
    // this exact schema stable while avoiding reuse of a key tied to a previous
    // unsupported payload. The value remains opaque and never leaves the server.
    $idempotencyKey = hash_hmac('sha256', 'boleto|orders-v3|' . $orderNumber, $accessToken);

    $response = svmp_api_request(
        'POST',
        '/v1/orders',
        $accessToken,
        $payload,
        $idempotencyKey
    );

    $payment = $response['transactions']['payments'][0] ?? [];
    $paymentMethod = is_array($payment) && is_array($payment['payment_method'] ?? null)
        ? $payment['payment_method']
        : [];
    $ticketUrl = trim((string)($paymentMethod['ticket_url'] ?? ''));
    $orderId = trim((string)($response['id'] ?? ''));

    if ($orderId === '' || !str_starts_with($ticketUrl, 'https://')) {
        throw new SvMercadoPagoApiException(502, 'boleto_missing_ticket');
    }

    return [
        'order_id' => $orderId,
        'payment_id' => (string)($payment['id'] ?? ''),
        'status' => (string)($payment['status'] ?? $response['status'] ?? 'action_required'),
        'status_detail' => (string)($payment['status_detail'] ?? $response['status_detail'] ?? 'waiting_payment'),
        'ticket_url' => $ticketUrl,
        'digitable_line' => (string)($paymentMethod['digitable_line'] ?? ''),
        'barcode_content' => (string)($paymentMethod['barcode_content'] ?? ''),
    ];
}

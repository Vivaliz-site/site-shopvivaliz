<?php
declare(strict_types=1);

header_remove('X-Powered-By');
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

// Rodada 4 (2026-08-19): este endpoint disparava uma chamada REAL a API do
// Mercado Pago (conta de producao) sem nenhuma autenticacao -- qualquer
// requisicao anonima consumia rate limit do gateway e sujava o historico da
// conta (confirmado ao vivo: 422 do proprio Mercado Pago, ou seja a chamada
// chegou la). Mesmo padrao ja usado em api/catalog/clean-deleted.php. Ver
// R4-5 no relatorio da Rodada 4 de melhoria continua.
require_once __DIR__ . '/../config/require-agent-key.php';
sv_require_agent_key();

$runtimeSecretsFile = __DIR__ . '/../config/runtime-secrets.php';
if (is_file($runtimeSecretsFile)) {
    $secrets = require $runtimeSecretsFile;
    if (is_array($secrets)) {
        foreach ($secrets as $k => $v) {
            if (!getenv($k)) {
                putenv($k . '=' . (string)$v);
            }
        }
    }
}

require_once __DIR__ . '/../includes/mercadopago-gateway.php';

function svmp_test_order_response(int $status, array $payload): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$accessToken = svmp_env('MERCADOPAGO_ACCESS_TOKEN');
$publicKey = svmp_env('MERCADOPAGO_PUBLIC_KEY');
$baseUrl = svmp_base_url();

if ($accessToken === '' || $publicKey === '') {
    svmp_test_order_response(503, ['ok' => false, 'error' => 'mercadopago_unconfigured']);
}

$externalReference = 'MP-QUALITY-' . gmdate('YmdHis') . '-' . bin2hex(random_bytes(4));
$statementDescriptor = 'SHOPVIVALIZ';
$deviceId = 'quality-test-' . gmdate('YmdHis');
$registrationDate = date(DATE_ATOM, strtotime('-30 days'));
$lastPurchase = date('Y-m-d H:i:s', strtotime('-2 days'));

$payload = [
    'type' => 'online',
    'processing_mode' => 'automatic',
    'external_reference' => $externalReference,
    'total_amount' => svmp_money(99.90),
    'description' => 'Pedido de teste de qualidade ShopVivaliz',
    'payer' => [
        'first_name' => 'Frederico',
        'last_name' => 'Mourao',
        'email' => 'shopvivaliz@gmail.com',
        'phone' => [
            'area_code' => '31',
            'number' => '999999999',
        ],
        'identification' => [
            'type' => 'CPF',
            'number' => '12345678909',
        ],
        'address' => [
            'street_name' => 'Rua Teste de Qualidade',
            'street_number' => '123',
            'zip_code' => '35500025',
            'neighborhood' => 'Centro',
            'city' => 'Divinopolis',
            'state' => 'MG',
        ],
    ],
    'items' => [
        [
            'id' => 'QUALITY-CHECKOUT-TEST',
            'title' => 'Chave Teste Qualidade MP',
            'description' => 'Produto de teste para medir qualidade da integração',
            'quantity' => 1,
            'currency_id' => 'BRL',
            'unit_price' => 99.90,
            'category_id' => 'others',
            'external_code' => 'quality-checkout-test',
        ],
    ],
    'transactions' => [
        'payments' => [[
            'amount' => svmp_money(99.90),
            'payment_method' => [
                'id' => 'boleto',
                'type' => 'ticket',
                'statement_descriptor' => $statementDescriptor,
            ],
        ]],
    ],
    'notification_url' => $baseUrl . '/api/webhook-mercadopago.php?source_news=webhooks',
    'statement_descriptor' => $statementDescriptor,
    'additional_info' => [
        'payer' => [
            'first_name' => 'Frederico',
            'last_name' => 'Mourao',
            'registration_date' => $registrationDate,
            'last_purchase' => $lastPurchase,
            'authentication_type' => 'email',
            'is_first_purchase_online' => false,
            'phone' => [
                'area_code' => '31',
                'number' => '999999999',
            ],
            'customer_id' => 'quality-test-frederico-mourao',
        ],
        'shipments' => [
            'express_shipments' => false,
            'receiver_address' => [
                'zip_code' => '35500025',
                'state_name' => 'Minas Gerais',
                'city_name' => 'Divinopolis',
                'street_name' => 'Rua Teste de Qualidade',
                'street_number' => '123',
                'neighborhood' => 'Centro',
            ],
            'receivers_address' => [
                'zip_code' => '35500025',
                'state_name' => 'Minas Gerais',
                'city_name' => 'Divinopolis',
                'street_name' => 'Rua Teste de Qualidade',
                'street_number' => '123',
                'neighborhood' => 'Centro',
            ],
        ],
    ],
];

try {
    $response = svmp_api_request(
        'POST',
        '/v1/orders',
        $accessToken,
        $payload,
        'quality-' . bin2hex(random_bytes(8)),
        $deviceId
    );

    $orderId = (string)($response['id'] ?? '');
    if ($orderId === '') {
        svmp_test_order_response(502, ['ok' => false, 'error' => 'invalid_order_response', 'response' => $response]);
    }

    svmp_test_order_response(201, [
        'ok' => true,
        'order_id' => $orderId,
        'external_reference' => $externalReference,
        'public_key' => $publicKey,
        'device_id' => $deviceId,
        'notification_url' => $payload['notification_url'],
        'statement_descriptor' => $statementDescriptor,
        'timestamp' => time(),
    ]);
} catch (SvMercadoPagoApiException $e) {
    svmp_test_order_response($e->httpStatus, ['ok' => false, 'error' => $e->publicCode]);
} catch (Throwable $e) {
    svmp_test_order_response(500, ['ok' => false, 'error' => 'internal_error']);
}

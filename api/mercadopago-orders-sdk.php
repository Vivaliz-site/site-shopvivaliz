<?php
/**
 * Integração com Mercado Pago Orders API - usando SDK Official
 * Documentação: https://www.mercadopago.com.br/developers/pt/docs/sdks-library/server-side
 */

declare(strict_types=1);

// Autoload do Composer
require_once __DIR__ . '/../vendor/autoload.php';

use MercadoPago\Client\Order\OrderClient;
use MercadoPago\MercadoPagoConfig;

// Load .env
$env = [];
$envFile = __DIR__ . '/../.env';
if (file_exists($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        if (str_starts_with(trim($line), '#') || !str_contains($line, '=')) continue;
        [$key, $value] = explode('=', $line, 2);
        $env[trim($key)] = trim(trim($value), "\"'");
    }
}

$accessToken = $env['MERCADOPAGO_ACCESS_TOKEN'] ?? '';

if (!$accessToken) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Mercado Pago access token not configured'
    ]);
    exit;
}

// Configurar SDK do Mercado Pago
MercadoPagoConfig::setAccessToken($accessToken);

/**
 * Criar Order no Mercado Pago usando SDK Official
 *
 * @param array $orderData Array com dados do pedido
 * @return array Resultado da criação
 */
function createOrderWithSDK(array $orderData): array
{
    try {
        $client = new OrderClient();

        // Montar request conforme spec da API
        $request = [
            'external_reference' => $orderData['external_reference'] ?? '',
            'total_amount' => (float)($orderData['total_amount'] ?? 0),
            'items' => $orderData['items'] ?? [],
            'payer' => $orderData['payer'] ?? [],
        ];

        // Validação
        if (empty($request['external_reference']) || $request['total_amount'] <= 0 || empty($request['items'])) {
            return [
                'success' => false,
                'error' => 'Missing required fields: external_reference, total_amount, items'
            ];
        }

        // Criar order usando SDK
        $order = $client->create($request);

        if ($order && isset($order->id)) {
            return [
                'success' => true,
                'order_id' => $order->id,
                'external_reference' => $order->external_reference ?? $request['external_reference'],
                'total_amount' => $order->total_amount ?? $request['total_amount'],
                'status' => $order->status ?? 'pending',
                'response' => (array)$order
            ];
        }

        return [
            'success' => false,
            'error' => 'Failed to create order',
            'response' => (array)$order
        ];

    } catch (Exception $e) {
        return [
            'success' => false,
            'error' => 'SDK Error: ' . $e->getMessage(),
            'code' => $e->getCode()
        ];
    }
}

// Endpoint POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');

    // Aceitar both application/json e application/x-www-form-urlencoded
    $input = json_decode(file_get_contents('php://input'), true) ?? $_POST;

    $result = createOrderWithSDK([
        'external_reference' => $input['external_reference'] ?? 'PED-' . date('YmdHis'),
        'total_amount' => $input['total_amount'] ?? 0,
        'items' => $input['items'] ?? [],
        'payer' => $input['payer'] ?? []
    ]);

    http_response_code($result['success'] ? 201 : 400);
    echo json_encode($result);
    exit;
}

// GET - Info
header('Content-Type: application/json');
echo json_encode([
    'api' => 'Mercado Pago Orders API (SDK Official)',
    'endpoint' => '/api/mercadopago-orders-sdk.php',
    'method' => 'POST',
    'sdk_version' => '2.0+',
    'docs' => 'https://www.mercadopago.com.br/developers/pt/docs/sdks-library/server-side',
    'example_request' => [
        'external_reference' => 'PED-20260714213526',
        'total_amount' => 76.00,
        'items' => [
            [
                'sku_number' => 'RODIZIO-75MM',
                'category' => 'Rodízios',
                'title' => 'Rodízio 75mm',
                'description' => 'Rodízio 75mm',
                'unit_price' => 76.00,
                'quantity' => 1
            ]
        ],
        'payer' => [
            'email' => 'cliente@test.com',
            'first_name' => 'Cliente',
            'last_name' => 'Teste',
            'phone' => '(37) 99999-1234'
        ]
    ]
]);

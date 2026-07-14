<?php
/**
 * Integração com Orders API do Mercado Pago
 * Conforme documentação: https://www.mercadopago.com.br/developers/pt/docs/checkout-api-orders
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/constants.php';

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

$mpToken = $env['MERCADOPAGO_ACCESS_TOKEN'] ?? '';

if (!$mpToken) {
    http_response_code(500);
    echo json_encode(['error' => 'Mercado Pago token not configured']);
    exit;
}

/**
 * Criar Order no Mercado Pago conforme API oficial
 */
function createMercadoPagoOrder(array $orderData): array
{
    global $mpToken;

    // Estrutura conforme documentação: https://www.mercadopago.com.br/developers/pt/docs/checkout-api-orders/create-application
    $payload = [
        'external_reference' => $orderData['external_reference'] ?? '',
        'total_amount' => (float)($orderData['total_amount'] ?? 0),
        'items' => $orderData['items'] ?? [],
        'payer' => $orderData['payer'] ?? [],
    ];

    // Validação básica
    if (empty($payload['external_reference']) || $payload['total_amount'] <= 0 || empty($payload['items'])) {
        return [
            'success' => false,
            'error' => 'Invalid order data: external_reference, total_amount, and items are required'
        ];
    }

    // POST para Orders API do Mercado Pago
    $ch = curl_init('https://api.mercadopago.com/v1/orders');
    curl_setopt_array($ch, [
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $mpToken,
        ],
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_TIMEOUT => 30,
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($error) {
        return [
            'success' => false,
            'error' => 'CURL Error: ' . $error
        ];
    }

    $data = json_decode($response, true);

    // Verificar resposta
    if ($httpCode === 201 && isset($data['id'])) {
        return [
            'success' => true,
            'order_id' => $data['id'],
            'external_reference' => $data['external_reference'] ?? $payload['external_reference'],
            'total_amount' => $data['total_amount'] ?? $payload['total_amount'],
            'status' => $data['status'] ?? 'pending',
            'response' => $data
        ];
    } else {
        return [
            'success' => false,
            'error' => $data['message'] ?? 'Failed to create order',
            'http_code' => $httpCode,
            'response' => $data
        ];
    }
}

// Endpoint POST para criar order
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $_SERVER['CONTENT_TYPE'] === 'application/json') {
    $input = json_decode(file_get_contents('php://input'), true);

    $result = createMercadoPagoOrder([
        'external_reference' => $input['external_reference'] ?? 'PED-' . date('YmdHis'),
        'total_amount' => $input['total_amount'] ?? 0,
        'items' => $input['items'] ?? [],
        'payer' => $input['payer'] ?? []
    ]);

    header('Content-Type: application/json');
    http_response_code($result['success'] ? 201 : 400);
    echo json_encode($result);
    exit;
}

// Se acessado diretamente via GET
header('Content-Type: application/json');
echo json_encode([
    'message' => 'Mercado Pago Orders API',
    'endpoint' => '/api/mercadopago-orders.php',
    'method' => 'POST',
    'example' => [
        'external_reference' => 'PED-20260714213526',
        'total_amount' => 76.00,
        'items' => [
            [
                'sku_number' => 'RODIZIO-75MM',
                'category' => 'Rodízios',
                'title' => 'Rodízio 75mm',
                'unit_price' => 76.00,
                'quantity' => 1
            ]
        ],
        'payer' => [
            'email' => 'cliente@test.com'
        ]
    ]
]);

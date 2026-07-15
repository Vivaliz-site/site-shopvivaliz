<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
http_response_code(400);

require_once dirname(__DIR__) . '/config/database.php';

// 1. Carregar secrets: getenv → $_ENV → .env
$runtimeSecretsFile = dirname(__DIR__) . '/config/runtime-secrets.php';
$secrets = (is_file($runtimeSecretsFile) && is_readable($runtimeSecretsFile))
    ? (array)require $runtimeSecretsFile
    : [];

function mp_get_secret(string $key, array $secrets): string {
    $value = getenv($key);
    if (is_string($value) && $value !== '') return $value;
    if (isset($secrets[$key])) return (string)$secrets[$key];
    if (isset($_ENV[$key])) return (string)$_ENV[$key];
    return '';
}

$accessToken = mp_get_secret('MERCADOPAGO_ACCESS_TOKEN', $secrets);
if ($accessToken === '') {
    echo json_encode(['ok' => false, 'error' => 'unconfigured']);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'method_not_allowed']);
    exit;
}

$input = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($input)) {
    echo json_encode(['ok' => false, 'error' => 'invalid_json']);
    exit;
}

// 2. Validar request
$orderId = (string)($input['order_id'] ?? '');
$externalRef = (string)($input['external_reference'] ?? '');
$paymentToken = (string)($input['payment_token'] ?? '');
$installments = (int)($input['installments'] ?? 1);

if (!$orderId || !$externalRef || !$paymentToken) {
    echo json_encode(['ok' => false, 'error' => 'missing_fields']);
    exit;
}

// 3. Buscar pedido no banco (NÃO confiar em transaction_amount do navegador)
try {
    $db = Database::getInstance();
    $stmt = $db->prepare('SELECT id, customer_email, total, status FROM orders WHERE id = ? LIMIT 1');
    $stmt->bind_param('s', $orderId);
    $stmt->execute();
    $result = $stmt->get_result();
    $order = $result->fetch_assoc();
    $stmt->close();

    if (!$order) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'order_not_found']);
        exit;
    }
} catch (Exception $e) {
    error_log('DB Error: order lookup failed for ' . $orderId);
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'internal_error']);
    exit;
}

// 4. Validações de negócio
$order['total'] = (float)$order['total'];

if ($order['total'] <= 0) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'invalid_order_total']);
    exit;
}

if ($order['status'] !== 'pendente_atendimento') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'order_already_processed']);
    exit;
}

// 5. Recalcular valor com base nos itens (defesa contra adulteração)
try {
    $stmt = $db->prepare('SELECT SUM(quantity * price) as calculated_total FROM order_items WHERE order_id = ?');
    $stmt->bind_param('s', $orderId);
    $stmt->execute();
    $itemResult = $stmt->get_result();
    $itemRow = $itemResult->fetch_assoc();
    $stmt->close();

    $calculatedTotal = (float)($itemRow['calculated_total'] ?? 0);

    // Permitir variação de 0.01 (centavo de diferença por arredondamento)
    if (abs($order['total'] - $calculatedTotal) > 0.01) {
        error_log("Total mismatch for $orderId: DB=$order[total] vs calculated=$calculatedTotal");
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'total_mismatch']);
        exit;
    }
} catch (Exception $e) {
    error_log('DB Error: item sum for ' . $orderId);
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'internal_error']);
    exit;
}

// 6. Chave de idempotência persistente por pedido
$idempotencyKey = "order-{$orderId}-" . substr(md5($paymentToken), 0, 8);

// 7. Chamar Mercado Pago SDK (usar chave oficial)
require_once dirname(__DIR__) . '/vendor/autoload.php';

try {
    $client = new MercadoPago\Client\PaymentClient();
    $client->setAccessToken($accessToken);

    $payment = [
        'transaction_amount' => $order['total'],
        'description' => "Pedido ShopVivaliz #{$orderId}",
        'payment_method_id' => 'visa',  // ou outro método suportado
        'payer' => [
            'email' => $order['customer_email'],
            'first_name' => 'Cliente',
            'last_name' => 'ShopVivaliz',
            'identification' => [
                'type' => 'CPF',
                'number' => '12345678909'  // Em produção, obter do formulário validado
            ]
        ],
        'external_reference' => $externalRef,
        'token' => $paymentToken,
        'installments' => max(1, min(12, $installments)),
        'statement_descriptor' => 'SHOPVIVALIZ',
        'capture' => true  // Capturar imediatamente
    ];

    $response = $client->create($payment);
    $paymentId = $response['id'] ?? null;
    $paymentStatus = $response['status'] ?? 'unknown';

    if (!$paymentId) {
        error_log("Payment creation failed for $orderId: " . json_encode($response));
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'payment_creation_failed']);
        exit;
    }

    // 8. Atualizar banco de dados (registrar APENAS Payment ID e status, não token)
    try {
        $stmt = $db->prepare('UPDATE orders SET mercadopago_payment_id = ?, payment_status = ?, updated_at = NOW() WHERE id = ?');
        $stmt->bind_param('sss', $paymentId, $paymentStatus, $orderId);
        $stmt->execute();
        $stmt->close();

        // Log seguro: APENAS IDs, nunca tokens ou cartões
        error_log("Payment processed: order=$orderId payment_id=$paymentId status=$paymentStatus");
    } catch (Exception $e) {
        error_log("DB update failed after payment: $orderId");
        // Mesmo se falhar o DB, o pagamento foi processado no MP
    }

    http_response_code(200);
    echo json_encode([
        'ok' => true,
        'payment_id' => (string)$paymentId,
        'order_id' => $orderId,
        'status' => $paymentStatus,
        'message' => 'Payment processed successfully'
    ]);
} catch (Exception $e) {
    error_log("Payment API error for $orderId: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'payment_api_error']);
}

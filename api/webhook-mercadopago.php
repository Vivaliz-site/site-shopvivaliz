<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once dirname(__DIR__) . '/config/database.php';

// 1. Carregar secrets
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
$webhookSecret = mp_get_secret('MERCADOPAGO_WEBHOOK_SECRET', $secrets);

if (!$accessToken || !$webhookSecret) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'unconfigured']);
    exit;
}

// Responder 200 OK IMEDIATAMENTE para evitar reprocessamento (Mercado Pago retry)
http_response_code(200);

// 2. Validar assinatura com classe oficial do SDK
try {
    require_once dirname(__DIR__) . '/vendor/autoload.php';

    $signature = $_SERVER['HTTP_X_SIGNATURE'] ?? '';
    $requestId = $_SERVER['HTTP_X_REQUEST_ID'] ?? '';
    $dataId = $_GET['data.id'] ?? $_GET['data_id'] ?? '';

    if (!$signature || !$requestId || !$dataId) {
        error_log("Webhook validation failed: missing headers (sig=$signature, req=$requestId, data=$dataId)");
        exit;
    }

    // Usar validador oficial do SDK
    $validator = new MercadoPago\Webhook\WebhookSignatureValidator();
    $isValid = $validator->validate(
        $webhookSecret,
        $requestId,
        (string)$dataId,
        $signature
    );

    if (!$isValid) {
        error_log("Webhook signature validation FAILED: $requestId / $dataId");
        exit;
    }

    error_log("Webhook signature validated: $requestId / $dataId");
} catch (Exception $e) {
    error_log("Webhook validation exception: " . $e->getMessage());
    exit;
}

// 3. Buscar pagamento na API para confirmar
try {
    $client = new MercadoPago\Client\PaymentClient();
    $client->setAccessToken($accessToken);

    $payment = $client->get((string)$dataId);

    $paymentId = $payment['id'] ?? null;
    $externalRef = $payment['external_reference'] ?? '';
    $mpStatus = $payment['status'] ?? 'unknown';
    $amount = (float)($payment['transaction_amount'] ?? 0);

    if (!$paymentId || !$externalRef) {
        error_log("Webhook: payment $dataId missing critical fields");
        exit;
    }

    // 4. Buscar pedido no banco
    $db = Database::getInstance();
    $stmt = $db->prepare('SELECT id, total, status FROM orders WHERE id = ? LIMIT 1');
    $stmt->bind_param('s', $externalRef);
    $stmt->execute();
    $result = $stmt->get_result();
    $order = $result->fetch_assoc();
    $stmt->close();

    if (!$order) {
        error_log("Webhook: order $externalRef not found");
        exit;
    }

    // 5. Validar valor (defesa contra adulteração)
    if (abs((float)$order['total'] - $amount) > 0.01) {
        error_log("Webhook: amount mismatch for $externalRef: db=" . $order['total'] . " mp=$amount");
        exit;
    }

    // 6. Mapear status Mercado Pago → status local
    $localStatus = match ($mpStatus) {
        'approved' => 'pagamento_confirmado',
        'pending' => 'pagamento_pendente',
        'in_process' => 'pagamento_em_processamento',
        'rejected' => 'pagamento_recusado',
        'cancelled' => 'pagamento_cancelado',
        'refunded' => 'pagamento_reembolsado',
        'charged_back' => 'chargeback',
        default => 'pagamento_desconhecido'
    };

    // 7. Tornar idempotente: verificar se já foi processado
    if ($order['status'] !== 'pendente_atendimento' && $order['status'] !== 'pagamento_pendente') {
        // Já foi processado, não atualizar novamente
        error_log("Webhook: order $externalRef already processed (status=" . $order['status'] . ")");
        exit;
    }

    // 8. Atualizar status do pedido (APENAS IDs nos logs)
    $stmt = $db->prepare('UPDATE orders SET payment_status = ?, mercadopago_payment_id = ?, status = ?, updated_at = NOW() WHERE id = ?');
    $stmt->bind_param('ssss', $mpStatus, $paymentId, $localStatus, $externalRef);
    $stmt->execute();
    $stmt->close();

    error_log("Webhook processed: order=$externalRef payment_id=$paymentId status=$localStatus");
} catch (Exception $e) {
    error_log("Webhook processing failed: " . $e->getMessage());
}

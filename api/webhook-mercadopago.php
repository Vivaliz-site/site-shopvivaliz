<?php
/**
 * Webhook Seguro Mercado Pago com Validação de Assinatura
 * Documentação: https://www.mercadopago.com.br/developers/pt/docs/webhooks/validate-signature
 *
 * Fluxo seguro:
 * 1. Receber notificação do Mercado Pago
 * 2. Validar assinatura X-Signature com X-Request-Id
 * 3. Responder 200 OK imediatamente
 * 4. Fazer GET /v1/payments/{id} para confirmar status
 * 5. Atualizar banco de dados
 */

declare(strict_types=1);

require_once __DIR__ . '/../vendor/autoload.php';

use MercadoPago\Client\Payment\PaymentClient;
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
$webhookSecret = $env['MERCADOPAGO_WEBHOOK_SECRET'] ?? ''; // Crypto Key do painel

/**
 * Validar assinatura HMAC-SHA256
 * Conforme documentação oficial do Mercado Pago
 */
function validateWebhookSignature(string $secret, string $requestId, string $timestamp, string $signature): bool
{
    if (empty($secret)) {
        error_log('⚠️ Webhook Secret não configurado no .env');
        return false;
    }

    // Montar string para assinar: {request-id}.{timestamp}.{secret}
    $dataToSign = "{$requestId}.{$timestamp}.{$secret}";

    // Gerar assinatura esperada com SHA256
    $expectedSignature = hash('sha256', $dataToSign);

    // Comparar usando comparação segura (timing-safe)
    $isValid = hash_equals($expectedSignature, $signature);

    if (!$isValid) {
        error_log("❌ Webhook signature inválida. Esperado: {$expectedSignature}, Recebido: {$signature}");
    }

    return $isValid;
}

/**
 * Processar webhook de pagamento
 */
function handlePaymentWebhook(array $data, string $accessToken): bool
{
    if (empty($accessToken)) {
        error_log('❌ Access token não configurado');
        return false;
    }

    // Extrair ID do pagamento
    $paymentId = $data['data']['id'] ?? null;

    if (!$paymentId) {
        error_log('❌ Payment ID não encontrado no webhook');
        return false;
    }

    try {
        // Configurar SDK
        MercadoPagoConfig::setAccessToken($accessToken);
        $client = new PaymentClient();

        // FLUXO SEGURO: Buscar pagamento direto na API (não confiar só no webhook)
        $payment = $client->get($paymentId);

        if (!$payment) {
            error_log("❌ Pagamento {$paymentId} não encontrado na API");
            return false;
        }

        // Extrair dados importantes
        $status = $payment->status ?? 'unknown';
        $externalReference = $payment->external_reference ?? null;
        $transactionAmount = $payment->transaction_amount ?? 0;
        $paymentMethod = $payment->payment_method_id ?? 'unknown';

        error_log("✅ Pagamento {$paymentId} validado - Status: {$status}");

        // Conectar ao banco de dados
        try {
            require_once __DIR__ . '/../config/database.php';
            $db = Database::getInstance()->getConnection();

            // Mapear status do Mercado Pago para status local
            $statusLocal = match($status) {
                'approved' => 'pago',
                'pending' => 'pendente_pagamento',
                'in_process' => 'em_analise',
                'rejected' => 'recusado',
                'cancelled' => 'cancelado',
                'refunded' => 'reembolsado',
                default => 'desconhecido'
            };

            // Atualizar pedido no banco
            $stmt = $db->prepare('
                UPDATE orders
                SET
                    mercadopago_payment_id = ?,
                    status = ?,
                    payment_method = ?,
                    transaction_amount = ?,
                    updated_at = NOW()
                WHERE external_reference = ?
            ');

            $stmt->bind_param('sssds', $paymentId, $statusLocal, $paymentMethod, $transactionAmount, $externalReference);
            $result = $stmt->execute();

            if ($result) {
                error_log("✅ Pedido {$externalReference} atualizado com sucesso");

                // Disparar eventos adicionais baseado no status
                if ($status === 'approved') {
                    // Aqui você pode enviar e-mail, gerar nota fiscal, etc.
                    error_log("📧 Evento: Pagamento aprovado - Pedido {$externalReference}");
                }

                return true;
            } else {
                error_log("❌ Erro ao atualizar pedido no banco: " . $db->error);
                return false;
            }

        } catch (Exception $e) {
            error_log("❌ Erro no banco de dados: " . $e->getMessage());
            return false;
        }

    } catch (Exception $e) {
        error_log("❌ Erro ao processar webhook: " . $e->getMessage());
        return false;
    }
}

// =======================
// EXECUÇÃO DO WEBHOOK
// =======================

// 1. Responder 200 OK IMEDIATAMENTE para o Mercado Pago parar de tentar reenviar
http_response_code(200);
header('Content-Type: application/json');

// 2. Capturar payload e headers
$payload = file_get_contents('php://input');
$data = json_decode($payload, true) ?? [];

$xRequestId = $_SERVER['HTTP_X_REQUEST_ID'] ?? '';
$xSignature = $_SERVER['HTTP_X_SIGNATURE'] ?? '';
$xTimestamp = $_SERVER['HTTP_X_TIMESTAMP'] ?? '';

// Log da notificação recebida
error_log("🔔 Webhook recebido - Tipo: " . ($data['type'] ?? 'unknown'));
error_log("   Request ID: {$xRequestId}");
error_log("   Timestamp: {$xTimestamp}");

// 3. Validar assinatura (Se a chave estiver configurada)
if (!empty($webhookSecret)) {
    if (!validateWebhookSignature($webhookSecret, $xRequestId, $xTimestamp, $xSignature)) {
        error_log("❌ Assinatura inválida - webhook rejeitado");
        echo json_encode(['status' => 'rejected', 'reason' => 'invalid_signature']);
        exit;
    }
    error_log("✅ Assinatura validada com sucesso");
} else {
    error_log("⚠️ MERCADOPAGO_WEBHOOK_SECRET não configurado - pulando validação");
}

// 4. Processar apenas notificações de pagamento
if (isset($data['type']) && $data['type'] === 'payment') {
    $success = handlePaymentWebhook($data, $accessToken);

    echo json_encode([
        'status' => $success ? 'processed' : 'error',
        'payment_id' => $data['data']['id'] ?? null,
        'timestamp' => date('c')
    ]);
} else {
    error_log("⚠️ Webhook ignorado - tipo: " . ($data['type'] ?? 'unknown'));

    echo json_encode([
        'status' => 'ignored',
        'reason' => 'not_a_payment_notification',
        'type' => $data['type'] ?? null
    ]);
}
?>

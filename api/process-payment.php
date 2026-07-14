<?php
/**
 * Processar Pagamento via Mercado Pago API
 * Recebe dados do Payment Brick e processa via API oficial
 */

declare(strict_types=1);

// Autoload
require_once __DIR__ . '/../vendor/autoload.php';

use MercadoPago\Client\Payment\PaymentClient;
use MercadoPago\MercadoPagoConfig;

header('Content-Type: application/json');

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
        'error' => 'Access token not configured'
    ]);
    exit;
}

// Configurar SDK
MercadoPagoConfig::setAccessToken($accessToken);

// Receber dados do Payment Brick
$input = json_decode(file_get_contents('php://input'), true) ?? [];

try {
    // Validar dados
    if (empty($input['token']) && empty($input['payment_method_id'])) {
        throw new Exception('Missing payment data');
    }

    // Montar requisição de pagamento conforme API
    $paymentRequest = [
        'transaction_amount' => (float)($input['transaction_amount'] ?? 0),
        'token' => $input['token'] ?? null,
        'payment_method_id' => $input['payment_method_id'] ?? null,
        'installments' => (int)($input['installments'] ?? 1),
        'payer' => $input['payer'] ?? [],
        'external_reference' => $input['order_id'] ?? ''
    ];

    // Remover valores nulos
    $paymentRequest = array_filter($paymentRequest, fn($v) => $v !== null);

    // Criar cliente de pagamento
    $client = new PaymentClient();

    // Processar pagamento
    $payment = $client->create($paymentRequest);

    // Verificar resultado
    if ($payment && isset($payment->id)) {
        // Salvar payment ID no BD associado ao order
        $orderId = $input['order_id'] ?? null;
        if ($orderId) {
            try {
                require_once __DIR__ . '/../config/database.php';
                $db = Database::getInstance()->getConnection();

                $stmt = $db->prepare('UPDATE orders SET mercadopago_payment_id = ?, status = ?, updated_at = NOW() WHERE id = ?');
                $status = $payment->status === 'approved' ? 'pago' : 'pendente_pagamento';
                $stmt->bind_param('sss', $payment->id, $status, $orderId);
                $stmt->execute();
            } catch (Exception $e) {
                error_log('Erro ao atualizar BD: ' . $e->getMessage());
            }
        }

        http_response_code(200);
        echo json_encode([
            'success' => true,
            'payment_id' => $payment->id,
            'status' => $payment->status,
            'transaction_amount' => $payment->transaction_amount,
            'payment_method' => $payment->payment_method_id ?? 'boleto'
        ]);
    } else {
        throw new Exception('Payment creation failed');
    }

} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => 'Payment processing error: ' . $e->getMessage()
    ]);
}

<?php
declare(strict_types=1);
/**
 * Webhook Post Processor
 * Executa após webhook ser processado
 * Envia email de confirmação com ID correto do Mercado Pago
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit;
}

// Carregar .env
$envFile = __DIR__ . '/../.env';
if (is_file($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos($line, '=') !== false && !str_starts_with($line, '#')) {
            [$key, $value] = explode('=', $line, 2);
            if (!getenv(trim($key))) {
                putenv(trim($key) . '=' . trim($value));
            }
        }
    }
}

require_once __DIR__ . '/../includes/mercadopago-gateway.php';
require_once __DIR__ . '/../includes/OrderNotificationService.class.php';

// Argumentos
$orderNumber = $argv[1] ?? '';
$orderPath = $argv[2] ?? '';

if (empty($orderNumber) || empty($orderPath) || !is_file($orderPath)) {
    echo "Uso: php webhook-post-processor.php ORDER_NUMBER ORDER_PATH\n";
    exit(1);
}

// Ler arquivo de pedido
$orderData = json_decode((string)file_get_contents($orderPath), true);
if (!is_array($orderData)) {
    echo "❌ Erro: Não consegui ler o pedido\n";
    exit(1);
}

// Extrair dados
$orderNumber = (string)($orderData['order_number'] ?? $orderNumber);
$customer = is_array($orderData['customer'] ?? null) ? $orderData['customer'] : [];
$customerEmail = (string)($customer['email'] ?? $orderData['customer_email'] ?? '');
$customerName = (string)($customer['name'] ?? $orderData['customer_name'] ?? 'Cliente');
$total = (float)($orderData['total'] ?? 0);
$status = (string)($orderData['status'] ?? 'pending');

// ID do Mercado Pago (AGORA CORRETO - vem do webhook)
$mpOrderId = (string)($orderData['mercadopago']['order_id'] ?? '');
$mpPaymentId = (string)($orderData['mercadopago']['payment_id'] ?? '');
$mpStatus = (string)($orderData['mercadopago']['status'] ?? 'pending');

echo "═══════════════════════════════════════════════════════\n";
echo "WEBHOOK POST PROCESSOR\n";
echo "═══════════════════════════════════════════════════════\n\n";

echo "📋 Pedido:\n";
echo "   Order Number: $orderNumber\n";
echo "   Cliente: $customerName\n";
echo "   Email: $customerEmail\n";
echo "   Total: R$ " . number_format($total, 2, ',', '.') . "\n";
echo "   Status: $status\n\n";

echo "🔔 Mercado Pago:\n";
echo "   Order ID: $mpOrderId\n";
echo "   Payment ID: $mpPaymentId\n";
echo "   Status: $mpStatus\n\n";

// Só enviar email se pagamento foi aprovado
if ($status !== 'payment_approved' && $mpStatus !== 'approved') {
    echo "⏳ Status não é 'approved' - email não será enviado\n";
    echo "   (Será enviado quando o status mudar para 'approved')\n";
    exit(0);
}

// Validar dados antes de enviar email
if (empty($customerEmail) || !filter_var($customerEmail, FILTER_VALIDATE_EMAIL)) {
    echo "❌ Email inválido: $customerEmail\n";
    exit(1);
}

echo "📧 Enviando email de confirmação via OrderNotificationService (SMTP real)...\n";

try {
    $service = OrderNotificationService::getInstance();
    $sent = $service->notifyOrderEvent(
        $orderNumber,
        'pagamento_aprovado',
        $orderData,
        $mpPaymentId !== '' ? $mpPaymentId : null
    );

    if ($sent) {
        echo "✅ EMAIL ENVIADO COM SUCESSO!\n\n";
        echo "   Para: $customerEmail\n";
        echo "   Evento: pagamento_aprovado\n";
        exit(0);
    }
} catch (Throwable $e) {
    error_log('[MercadoPago] OrderNotificationService unavailable, falling back to direct SMTP email: ' . $e->getMessage());
}

require_once __DIR__ . '/../api/emails/send-order-notification.php';
$sentFallback = svem_send_order_email($orderData, 'payment_received');
if ($sentFallback) {
    echo "✅ EMAIL ENVIADO COM FALLBACK SMTP DIRETO!\n\n";
    echo "   Para: $customerEmail\n";
    echo "   Evento: payment_received\n";
    exit(0);
}

echo "❌ Falha ao enviar email (fallback SMTP também falhou)\n";
exit(1);

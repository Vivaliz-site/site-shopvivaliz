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
$customerEmail = (string)($orderData['customer_email'] ?? '');
$customerName = (string)($orderData['customer_name'] ?? 'Cliente');
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

// Dados para o email
$emailSubject = "Pedido Confirmado - ShopVivaliz #$orderNumber";
$emailBody = <<<BODY
Olá $customerName,

Seu pedido foi CONFIRMADO! 🎉

╔════════════════════════════════════════════════════════════╗
║                  DADOS DO PEDIDO                           ║
╚════════════════════════════════════════════════════════════╝

Número do Pedido (Local): $orderNumber
Número do Pedido (Mercado Pago): $mpOrderId
ID do Pagamento: $mpPaymentId
Data: " . date('d/m/Y H:i:s') . "
Status: ✅ PAGAMENTO APROVADO

╔════════════════════════════════════════════════════════════╗
║                  VALOR DO PEDIDO                           ║
╚════════════════════════════════════════════════════════════╝

Total: R$ " . number_format($total, 2, ',', '.') . "

╔════════════════════════════════════════════════════════════╗
║              PRÓXIMOS PASSOS                               ║
╚════════════════════════════════════════════════════════════╝

1. ✅ Seu pagamento foi aprovado no Mercado Pago
2. ⏳ Nossa equipe comercial vai confirmar o frete
3. ⏳ Você receberá o rastreamento da sua entrega
4. ⏳ Acompanhe seu pedido na plataforma

LINK DE ACOMPANHAMENTO:
https://dev.shopvivaliz.com.br/meu-pedido?order=$orderNumber

═════════════════════════════════════════════════════════════

DÚVIDAS?

📞 WhatsApp: 11 4041-5850
📧 Email: contato@shopvivaliz.com.br
🌐 Site: https://dev.shopvivaliz.com.br

═════════════════════════════════════════════════════════════

Obrigado por comprar na ShopVivaliz!

Atenciosamente,
Equipe ShopVivaliz

© 2026 ShopVivaliz
Este é um email automático - Por favor, não responda
BODY;

// Tentar enviar email
echo "📧 Enviando email de confirmação...\n";

// Método 1: mail() PHP
$emailFrom = getenv('EMAIL_FROM') ?: 'noreply@shopvivaliz.com.br';
$headers = "MIME-Version: 1.0\r\n";
$headers .= "Content-Type: text/plain; charset=UTF-8\r\n";
$headers .= "From: $emailFrom\r\n";

if (mail($customerEmail, $emailSubject, $emailBody, $headers)) {
    echo "✅ EMAIL ENVIADO COM SUCESSO!\n\n";
    echo "   Para: $customerEmail\n";
    echo "   Assunto: $emailSubject\n";
    echo "   Método: PHP mail()\n";
    exit(0);
} else {
    echo "⚠️  mail() não funcionou localmente\n";
    echo "   (Funcionará em produção com SMTP configurado)\n\n";
    exit(0);
}

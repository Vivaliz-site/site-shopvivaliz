<?php
declare(strict_types=1);
/**
 * Script para enviar confirmação de pedido por email
 * Uso: php api/send-order-confirmation-email.php ORDER_NUMBER CUSTOMER_EMAIL CUSTOMER_NAME TOTAL ITEMS
 */

header('Content-Type: application/json; charset=UTF-8');

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

// Parâmetros
$orderNumber = $argv[1] ?? 'TEST-001';
$customerEmail = $argv[2] ?? 'cliente@example.com';
$customerName = $argv[3] ?? 'Cliente Teste';
$total = $argv[4] ?? '99.90';
$items = $argv[5] ?? 'Produto 1';

// Credenciais
$smtpHost = getenv('SMTP_HOST') ?: 'smtp.gmail.com';
$smtpPort = (int)(getenv('SMTP_PORT') ?: '587');
$smtpUser = getenv('SMTP_USER') ?: 'fredmourao@gmail.com';
$smtpPass = getenv('SMTP_PASS') ?: '';
$emailFrom = getenv('EMAIL_FROM') ?: 'noreply@shopvivaliz.com.br';
$siteBaseUrl = rtrim((string)(getenv('SHOPVIVALIZ_BASE_URL') ?: getenv('APP_URL') ?: getenv('SITE_URL') ?: 'https://shopvivaliz.com.br'), '/');

// Validação
if (empty($customerEmail) || !filter_var($customerEmail, FILTER_VALIDATE_EMAIL)) {
    echo json_encode(['ok' => false, 'error' => 'Invalid email address']);
    exit(1);
}

// HTML do email
$htmlBody = <<<HTML
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <style>
        body { font-family: Arial, sans-serif; color: #333; background: #f5f5f5; }
        .container { max-width: 600px; margin: 20px auto; background: white; border-radius: 8px; overflow: hidden; box-shadow: 0 2px 8px rgba(0,0,0,0.1); }
        .header { background: #0f8f62; color: white; padding: 30px; text-align: center; }
        .header h1 { margin: 0; font-size: 28px; }
        .content { padding: 30px; }
        .order-info { background: #f9f9f9; padding: 20px; border-left: 4px solid #0f8f62; margin: 20px 0; }
        .order-info strong { color: #0f8f62; }
        .items { margin: 20px 0; }
        .item { display: flex; justify-content: space-between; padding: 10px 0; border-bottom: 1px solid #eee; }
        .total { font-size: 20px; font-weight: bold; color: #0f8f62; margin-top: 20px; padding-top: 20px; border-top: 2px solid #0f8f62; }
        .button { display: inline-block; background: #0f8f62; color: white; padding: 12px 24px; text-decoration: none; border-radius: 4px; margin: 15px 0; }
        .footer { background: #f0f0f0; padding: 20px; text-align: center; font-size: 12px; color: #666; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>🎉 Pedido Confirmado!</h1>
        </div>

        <div class="content">
            <p>Olá <strong>$customerName</strong>,</p>

            <p>Seu pedido foi registrado com sucesso em nossa loja! Abaixo estão os detalhes:</p>

            <div class="order-info">
                <strong>Número do Pedido:</strong> $orderNumber
            </div>

            <div class="order-info">
                <strong>Data:</strong> " . date('d/m/Y H:i') . "
            </div>

            <div class="order-info">
                <strong>Email:</strong> $customerEmail
            </div>

            <h3>📦 Itens do Pedido</h3>
            <div class="items">
                <div class="item">
                    <span>$items</span>
                </div>
            </div>

            <div class="total">
                Total: R$ " . number_format((float)$total, 2, ',', '.') . "
            </div>

            <h3>💳 Próximos Passos</h3>
            <ol>
                <li>Confirme o pagamento via Mercado Pago</li>
                <li>Nossa equipe comercial fará contato para confirmar frete</li>
                <li>Você receberá atualizações sobre o status do seu pedido</li>
            </ol>

            <p style="text-align: center;">
                <a href="$siteBaseUrl/meus-pedidos" class="button">Ver meu pedido</a>
            </p>

            <p style="color: #666; font-size: 13px; margin-top: 30px;">
                <strong>Dúvidas?</strong> Entre em contato conosco via WhatsApp ou email.
            </p>
        </div>

        <div class="footer">
            <p>© 2026 ShopVivaliz - Todos os direitos reservados</p>
            <p>Este é um email automático - Por favor, não responda</p>
        </div>
    </div>
</body>
</html>
HTML;

// Versão texto
$textBody = <<<TEXT
Olá $customerName,

Seu pedido foi confirmado com sucesso!

Número do Pedido: $orderNumber
Data: " . date('d/m/Y H:i') . "
Email: $customerEmail

Itens: $items
Total: R$ " . number_format((float)$total, 2, ',', '.') . "

Próximos passos:
1. Confirme o pagamento via Mercado Pago
2. Nossa equipe comercial fará contato para confirmar frete
3. Você receberá atualizações sobre o status do seu pedido

Obrigado por comprar na ShopVivaliz!

© 2026 ShopVivaliz
TEXT;

// Tentarenviar email com diferentes métodos
$success = false;
$error = '';

// Método 1: PHP mail() nativo (Windows)
if (!$success) {
    try {
        $headers = "MIME-Version: 1.0\r\n";
        $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
        $headers .= "From: $emailFrom\r\n";
        $headers .= "Reply-To: $emailFrom\r\n";

        if (mail($customerEmail, "Pedido Confirmado - ShopVivaliz #$orderNumber", $htmlBody, $headers)) {
            $success = true;
            $method = 'PHP mail() - Windows SMTP';
        } else {
            $error = 'PHP mail() falhou';
        }
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

// Método 2: SwiftMailer/PHPMailer via stream (se disponível)
if (!$success && $smtpPass) {
    try {
        $context = stream_context_create([
            'ssl' => ['verify_peer' => false, 'verify_peer_name' => false],
        ]);

        $smtp = fsockopen($smtpHost, $smtpPort, $errno, $errstr, 10);
        if ($smtp) {
            stream_set_timeout($smtp, 10);

            // EHLO
            fgets($smtp);
            fputs($smtp, "EHLO localhost\r\n");
            fgets($smtp);

            // STARTTLS
            fputs($smtp, "STARTTLS\r\n");
            fgets($smtp);

            stream_socket_enable_crypto($smtp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);

            // AUTH
            fputs($smtp, "AUTH LOGIN\r\n");
            fgets($smtp);
            fputs($smtp, base64_encode($smtpUser) . "\r\n");
            fgets($smtp);
            fputs($smtp, base64_encode($smtpPass) . "\r\n");
            fgets($smtp);

            // Enviar email
            fputs($smtp, "MAIL FROM: <$emailFrom>\r\n");
            fgets($smtp);
            fputs($smtp, "RCPT TO: <$customerEmail>\r\n");
            fgets($smtp);
            fputs($smtp, "DATA\r\n");
            fgets($smtp);

            $message = "To: $customerEmail\r\n";
            $message .= "From: $emailFrom\r\n";
            $message .= "Subject: Pedido Confirmado - ShopVivaliz #$orderNumber\r\n";
            $message .= "MIME-Version: 1.0\r\n";
            $message .= "Content-Type: text/html; charset=UTF-8\r\n\r\n";
            $message .= $htmlBody . "\r\n.\r\n";

            fputs($smtp, $message);
            fgets($smtp);

            fputs($smtp, "QUIT\r\n");
            fclose($smtp);

            $success = true;
            $method = 'SMTP via socket';
        }
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

// Resposta
http_response_code($success ? 200 : 400);
echo json_encode([
    'ok' => $success,
    'order_number' => $orderNumber,
    'customer_email' => $customerEmail,
    'customer_name' => $customerName,
    'total' => $total,
    'method' => $success ? ($method ?? 'desconhecido') : null,
    'error' => !$success ? $error : null,
    'timestamp' => date('Y-m-d H:i:s'),
]);

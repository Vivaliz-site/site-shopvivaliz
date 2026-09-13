<?php
declare(strict_types=1);
/**
 * Script para enviar confirmação de pedido por email
 * Uso: php api/send-order-confirmation-email.php ORDER_NUMBER CUSTOMER_EMAIL CUSTOMER_NAME TOTAL ITEMS
 */

header('Content-Type: application/json; charset=UTF-8');

// Rodada 10 (2026-08-19) - R10-4: dos 3 scripts de CLI em api/, este era o unico sem
// guarda de SAPI -- e e' o que envia e-mail. Qualquer request HTTP anonimo disparava
// o envio com valores default; se register_argc_argv estiver On no PHP-FPM da VM
// (a confirmar), $argv vem da query string e destinatario/conteudo ficam controlados
// pelo atacante (relay de e-mail aberto). Mesmo padrao ja usado em
// api/melhorenvio/generate-label-background.php e api/webhook-post-processor.php.
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}

require_once __DIR__ . '/../scripts/mailer.php';

// Parâmetros
$orderNumber = $argv[1] ?? 'TEST-001';
$customerEmail = $argv[2] ?? 'cliente@example.com';
$customerName = $argv[3] ?? 'Cliente Teste';
$total = $argv[4] ?? '99.90';
$items = $argv[5] ?? 'Produto 1';

$siteBaseUrl = sv_mailer_site_url();

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
                <li>O frete informado no checkout foi registrado e será acompanhado junto ao pedido</li>
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
2. O frete informado no checkout foi registrado no pedido
3. Você receberá atualizações sobre o status do seu pedido

Obrigado por comprar na ShopVivaliz!

© 2026 ShopVivaliz
TEXT;

// Envio pelo transporte centralizado e validado da loja.
$success = send_email($customerEmail, "Pedido Confirmado - ShopVivaliz #$orderNumber", $htmlBody, $textBody);
$error = $success ? '' : 'Falha no mailer central';
$method = $success ? 'central_mailer' : null;

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

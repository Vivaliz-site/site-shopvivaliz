<?php
/**
 * Sistema de Notificação de Pedidos por Email
 * Envia emails para clientes em:
 * - Pedido criado/confirmado
 * - Alteração de status
 * - Pagamento recebido
 * - Envio despachado
 * - Boleto gerado
 */

declare(strict_types=1);

function svem_send_order_email(array $order, string $event = 'order_created'): bool
{
    $email = $order['customer']['email'] ?? '';
    $name = $order['customer']['name'] ?? 'Cliente';

    if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        error_log("[OrderEmail] Email inválido para pedido {$order['order_number']}: $email");
        return false;
    }

    // Preparar conteúdo do email baseado no evento
    [$subject, $htmlBody] = svem_build_email_content($order, $event, $name);

    // Usava mail() PHP nativo direto, que sempre falhava silenciosamente
    // porque o servidor nao tem sendmail instalado ("sh: /usr/sbin/sendmail:
    // not found") -- todo email de pedido (order_created em diante) nunca
    // saia de fato. send_email() ja usa PHPMailer real via SMTP (mesmo
    // caminho corrigido em scripts/mailer.php), so faltava esse arquivo usar.
    require_once dirname(__DIR__, 2) . '/scripts/mailer.php';
    $success = send_email($email, $subject, $htmlBody);

    if ($success) {
        error_log("[OrderEmail] ✅ Email enviado para $email (evento: $event, pedido: {$order['order_number']})");
    } else {
        error_log("[OrderEmail] ❌ Falha ao enviar email para $email (evento: $event)");
    }

    return $success;
}

function svem_build_email_content(array $order, string $event, string $customerName): array
{
    $siteBaseUrl = rtrim((string)(getenv('SHOPVIVALIZ_BASE_URL') ?: getenv('APP_URL') ?: getenv('SITE_URL') ?: 'https://shopvivaliz.com.br'), '/');
    $orderNumber = isset($order['order_number']) ? $order['order_number'] : 'N/A';
    $orderDate = date('d/m/Y H:i', strtotime(isset($order['created_at']) ? $order['created_at'] : 'now'));
    $total = number_format(isset($order['total']) ? $order['total'] : 0, 2, ',', '.');
    $phone = isset($order['customer']['phone']) ? $order['customer']['phone'] : '';
    $whatsapp = getenv('LOJA_WHATSAPP') ?: '551140415850';
    $whatsappLink = "https://wa.me/" . preg_replace('/\D/', '', $whatsapp);
    $trackingCode = isset($order['tracking_code']) ? $order['tracking_code'] : 'Será enviado em breve';
    $address = isset($order['customer']['address']) ? $order['customer']['address'] : '';
    $neighborhood = isset($order['customer']['neighborhood']) ? $order['customer']['neighborhood'] : '';
    $city = isset($order['customer']['city']) ? $order['customer']['city'] : '';
    $state = isset($order['customer']['state']) ? $order['customer']['state'] : '';
    $cep = isset($order['customer']['cep']) ? $order['customer']['cep'] : '';

    $itemsHtml = '';
    foreach ($order['items'] ?? [] as $item) {
        $itemName = $item['name'] ?? '';
        $qty = $item['quantity'] ?? 0;
        $price = number_format($item['price'] ?? 0, 2, ',', '.');
        $itemsHtml .= "<tr>
            <td style='padding:8px; border-bottom:1px solid #eee;'>$itemName</td>
            <td style='padding:8px; border-bottom:1px solid #eee; text-align:center;'>$qty</td>
            <td style='padding:8px; border-bottom:1px solid #eee; text-align:right;'>R\$ $price</td>
        </tr>";
    }

    $statusBadge = match ($event) {
        'order_created' => '<span style="background:#4CAF50; color:white; padding:6px 12px; border-radius:4px; font-weight:bold;">✅ AGUARDANDO CONFIRMAÇÃO</span>',
        'payment_received' => '<span style="background:#2196F3; color:white; padding:6px 12px; border-radius:4px; font-weight:bold;">💳 PAGAMENTO RECEBIDO</span>',
        'shipping_dispatched' => '<span style="background:#FF9800; color:white; padding:6px 12px; border-radius:4px; font-weight:bold;">📦 ENVIADO</span>',
        'shipped' => '<span style="background:#FF9800; color:white; padding:6px 12px; border-radius:4px; font-weight:bold;">📦 EM TRÂNSITO</span>',
        'delivered' => '<span style="background:#8BC34A; color:white; padding:6px 12px; border-radius:4px; font-weight:bold;">✅ ENTREGUE</span>',
        'boleto_generated' => '<span style="background:#FFC107; color:#333; padding:6px 12px; border-radius:4px; font-weight:bold;">📄 BOLETO DISPONÍVEL</span>',
        'status_changed' => '<span style="background:#9C27B0; color:white; padding:6px 12px; border-radius:4px; font-weight:bold;">🔄 ATUALIZAÇÃO</span>',
        default => '<span style="background:#757575; color:white; padding:6px 12px; border-radius:4px; font-weight:bold;">📋 PEDIDO ATUALIZADO</span>',
    };

    $messageBodies = [
        'order_created' => "
            <p>Olá <strong>$customerName</strong>,</p>
            <p>Seu pedido foi recebido com sucesso! 🎉</p>
            <p>Número do pedido: <strong>$orderNumber</strong><br>
            Data: <strong>$orderDate</strong></p>
            <p><strong>Próximos passos:</strong></p>
            <ul>
                <li>Você receberá confirmação de frete em breve</li>
                <li>Assim que confirmado, enviaremos link de pagamento</li>
                <li>Acompanhe seu pedido através do número acima</li>
            </ul>
            <p>❓ Dúvidas? <a href='$whatsappLink' style='color:#25D366; text-decoration:none; font-weight:bold;'>Fale conosco no WhatsApp</a></p>
        ",
        'payment_received' => "
            <p>Olá <strong>$customerName</strong>,</p>
            <p>Pagamento confirmado com sucesso! 💚</p>
            <p>Número do pedido: <strong>$orderNumber</strong><br>
            Valor: <strong>R\$ $total</strong></p>
            <p>Seu pedido foi enviado para processamento. Você receberá o código de rastreamento em breve!</p>
        ",
        'shipping_dispatched' => "
            <p>Olá <strong>$customerName</strong>,</p>
            <p>Seu pedido foi enviado! 📦</p>
            <p>Número do pedido: <strong>$orderNumber</strong><br>
            Status: <strong>EM TRÂNSITO</strong></p>
            <p>Código de rastreamento: <strong>$trackingCode</strong></p>
            <p>Acompanhe a entrega através do código acima no site dos Correios ou Melhor Envio.</p>
        ",
        'boleto_generated' => "
            <p>Olá <strong>$customerName</strong>,</p>
            <p>Boleto bancário gerado! 📄</p>
            <p>Número do pedido: <strong>$orderNumber</strong><br>
            Valor: <strong>R\$ $total</strong></p>
            <p>Você pode pagar o boleto através do link que foi enviado em seu email ou aplicativo bancário.</p>
            <p>⚠️ Data de vencimento: Verificar boleto</p>
        ",
    ];

    $subject = match ($event) {
        'order_created' => "Pedido recebido! Número $orderNumber",
        'payment_received' => "Pagamento confirmado - Pedido $orderNumber",
        'shipping_dispatched' => "Seu pedido foi enviado! - $orderNumber",
        'boleto_generated' => "Boleto disponível para pagamento - $orderNumber",
        default => "Atualização de pedido - $orderNumber",
    };

    $messageBody = $messageBodies[$event] ?? $messageBodies['order_created'];

    $html = "
    <!DOCTYPE html>
    <html lang='pt-BR'>
    <head>
        <meta charset='UTF-8'>
        <meta name='viewport' content='width=device-width, initial-scale=1.0'>
        <title>$subject</title>
    </head>
    <body style='font-family: Arial, sans-serif; line-height: 1.6; color: #333; max-width: 600px; margin: 0 auto; padding: 20px;'>
        <div style='background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 30px; text-align: center; border-radius: 8px 8px 0 0;'>
            <h1 style='margin: 0; font-size: 28px;'>ShopVivaliz</h1>
            <p style='margin: 5px 0 0 0; font-size: 14px;'>Sua loja de qualidade</p>
        </div>

        <div style='background: white; padding: 30px; border: 1px solid #ddd; border-top: none;'>
            <div style='text-align: center; margin-bottom: 20px;'>
                $statusBadge
            </div>

            $messageBody

            <hr style='border: none; border-top: 1px solid #eee; margin: 30px 0;'>

            <h3 style='color: #667eea; margin-top: 20px;'>Resumo do Pedido</h3>
            <table style='width: 100%; border-collapse: collapse; margin-bottom: 20px;'>
                <thead>
                    <tr style='background: #f5f5f5;'>
                        <th style='padding: 10px; text-align: left; border-bottom: 2px solid #ddd;'>Produto</th>
                        <th style='padding: 10px; text-align: center; border-bottom: 2px solid #ddd;'>Qtd</th>
                        <th style='padding: 10px; text-align: right; border-bottom: 2px solid #ddd;'>Valor</th>
                    </tr>
                </thead>
                <tbody>
                    $itemsHtml
                    <tr style='background: #f9f9f9; font-weight: bold;'>
                        <td colspan='2' style='padding: 10px; text-align: right;'>TOTAL:</td>
                        <td style='padding: 10px; text-align: right;'>R\$ $total</td>
                    </tr>
                </tbody>
            </table>

            <div style='background: #f0f0f0; padding: 15px; border-radius: 5px; margin: 20px 0;'>
                <strong>Informações de Entrega:</strong><br>
                $address<br>
                $neighborhood - $city/$state<br>
                CEP: $cep
            </div>

            <hr style='border: none; border-top: 1px solid #eee; margin: 30px 0;'>

            <p style='text-align: center; color: #666; font-size: 12px;'>
                <strong>Precisa de ajuda?</strong><br>
                📞 WhatsApp: <a href='$whatsappLink' style='color: #25D366; text-decoration: none;'>$whatsapp</a><br>
                📧 Email: contato@shopvivaliz.com.br<br>
                🌐 Site: <a href='{$siteBaseUrl}' style='color: #667eea; text-decoration: none;'>{$siteBaseUrl}</a>
            </p>

            <p style='text-align: center; color: #999; font-size: 11px; margin-top: 20px;'>
                Este é um email automático. Não responda este email.
            </p>
        </div>

        <div style='background: #333; color: white; padding: 15px; text-align: center; font-size: 11px; border-radius: 0 0 8px 8px;'>
            <p style='margin: 0;'>ShopVivaliz © 2026 - Todos os direitos reservados</p>
        </div>
    </body>
    </html>
    ";

    return [$subject, $html];
}

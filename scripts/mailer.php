<?php
/**
 * Módulo de envio de emails
 * Usa PHPMailer ou mail() nativo
 */

// class_exists() abaixo so encontra o PHPMailer se algo mais no request ja
// tiver carregado essas classes -- quando send_email() e chamado por um
// fluxo que nao passa por isso antes (ex: auth/forgot-password.php), o
// PHPMailer nunca e encontrado e cai no fallback mail() nativo, que falha
// sempre porque o servidor nao tem /usr/sbin/sendmail instalado. Confirmado
// ao vivo: send_email() retornava false sempre nesse cenario. Garantimos
// aqui que o PHPMailer real esteja sempre disponivel, independente de quem
// chamou este arquivo primeiro.
if (!class_exists('PHPMailer\PHPMailer\PHPMailer')) {
    $phpMailerDir = dirname(__DIR__) . '/includes/PHPMailer';
    if (is_file($phpMailerDir . '/Exception.php')) {
        require_once $phpMailerDir . '/Exception.php';
        require_once $phpMailerDir . '/PHPMailer.php';
        require_once $phpMailerDir . '/SMTP.php';
    }
}

function sv_mailer_site_url(): string
{
    $official = @include dirname(__DIR__) . '/config/official-site.php';
    if (is_array($official) && !empty($official['base_url'])) {
        return rtrim((string)$official['base_url'], '/');
    }

    $configured = trim((string)(getenv('SHOPVIVALIZ_BASE_URL') ?: getenv('APP_URL') ?: getenv('SITE_URL') ?: ''));
    if ($configured !== '') {
        return rtrim($configured, '/');
    }

    return 'https://shopvivaliz.com.br';
}

function get_mailer_config(): array
{
    return [
        'from_email' => getenv('EMAIL_FROM') ?: getenv('SMTP_USER') ?: getenv('EMAIL_USER') ?: getenv('MAIL_USER') ?: 'agentes@shopvivaliz.com.br',
        'from_name' => 'ShopVivaliz',
        'smtp_host' => getenv('SMTP_HOST') ?: getenv('EMAIL_SMTP_HOST') ?: getenv('MAIL_HOST') ?: 'smtp.titan.email',
        'smtp_port' => (int)(getenv('SMTP_PORT') ?: getenv('EMAIL_SMTP_PORT') ?: getenv('MAIL_PORT') ?: 465),
        'smtp_user' => getenv('SMTP_USER') ?: getenv('EMAIL_USER') ?: getenv('MAIL_USER') ?: 'agentes@shopvivaliz.com.br',
        'smtp_pass' => getenv('SMTP_PASS') ?: getenv('EMAIL_PASSWORD') ?: getenv('MAIL_PASS') ?: '',
        'smtp_secure' => ((int)(getenv('SMTP_PORT') ?: getenv('EMAIL_SMTP_PORT') ?: getenv('MAIL_PORT') ?: 465) === 465) ? 'ssl' : 'tls',
    ];
}

function send_email(string $to, string $subject, string $html, ?string $text = null): bool
{
    $config = get_mailer_config();

    // Se PHPMailer está disponível, usar
    if (class_exists('PHPMailer\PHPMailer\PHPMailer')) {
        return send_email_phpmailer($to, $subject, $html, $text, $config);
    }

    // Fallback: usar mail() nativo
    return send_email_native($to, $subject, $html, $config);
}

function send_email_phpmailer(
    string $to,
    string $subject,
    string $html,
    ?string $text,
    array $config
): bool {
    try {
        $mail = new \PHPMailer\PHPMailer\PHPMailer(true);

        $mail->isSMTP();
        $mail->Host = $config['smtp_host'];
        $mail->Port = $config['smtp_port'];
        $mail->SMTPSecure = $config['smtp_secure'];
        $mail->SMTPAuth = true;
        $mail->Timeout = 30;
        $mail->Username = $config['smtp_user'];
        $mail->Password = $config['smtp_pass'];

        $mail->setFrom($config['from_email'], $config['from_name']);
        $mail->addAddress($to);
        $mail->Subject = $subject;

        $mail->isHTML(true);
        $mail->Body = $html;
        if ($text) {
            $mail->AltBody = $text;
        }

        $mail->CharSet = 'UTF-8';
        $mail->Encoding = '8bit';

        return $mail->send();
    } catch (Exception $e) {
        error_log('PHPMailer error: ' . $e->getMessage());
        return false;
    }
}

function send_email_native(string $to, string $subject, string $html, array $config): bool
{
    $headers = [
        'MIME-Version: 1.0',
        'Content-Type: text/html; charset=UTF-8',
        'From: ' . $config['from_name'] . ' <' . $config['from_email'] . '>',
        'Reply-To: ' . $config['from_email'],
        'X-Mailer: ShopVivaliz/1.0',
    ];

    return mail(
        $to,
        $subject,
        $html,
        implode("\r\n", $headers)
    );
}

// Helpers específicos

function send_welcome_email(string $email, string $name): bool
{
    $siteBaseUrl = sv_mailer_site_url();
    $subject = 'Bem-vindo à ShopVivaliz!';

    $html = "<h2>Oi $name!</h2>";
    $html .= "<p>Obrigado por se cadastrar na ShopVivaliz.</p>";
    $html .= "<p>Sua conta foi criada com sucesso e você já pode começar a comprar.</p>";
    $html .= "<p><a href='{$siteBaseUrl}' style='background: #667eea; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px; display: inline-block;'>Ir para a Loja</a></p>";
    $html .= "<p>Se tiver dúvidas, nos envie um email!</p>";

    return send_email($email, $subject, $html);
}

function send_password_reset_email(string $email, string $name, string $reset_token): bool
{
    $reset_link = sv_mailer_site_url() . '/auth/reset-password.php?token=' . urlencode($reset_token);

    $subject = 'Redefinir sua senha na ShopVivaliz';

    $html = "<h2>Oi $name,</h2>";
    $html .= "<p>Recebemos uma solicitação para redefinir sua senha.</p>";
    $html .= "<p>Clique no link abaixo para criar uma nova senha:</p>";
    $html .= "<p><a href='$reset_link' style='background: #667eea; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px; display: inline-block;'>Redefinir Senha</a></p>";
    $html .= "<p>Este link expira em 24 horas.</p>";
    $html .= "<p>Se você não solicitou isso, ignore este email.</p>";

    return send_email($email, $subject, $html);
}

function svmp_send_pix_qr_email(
    string $email,
    string $name,
    string $orderNumber,
    float $total,
    string $qrCode,
    string $qrCodeBase64
): bool {
    $config = get_mailer_config();
    $totalFmt = number_format($total, 2, ',', '.');
    $subject = "Pague com Pix - Pedido $orderNumber - ShopVivaliz";

    $html = "<h2>Oi $name,</h2>";
    $html .= "<p>Recebemos seu pedido <strong>#$orderNumber</strong>! Falta só o pagamento via Pix para confirmarmos.</p>";
    $html .= "<p><strong>Valor:</strong> R$ $totalFmt</p>";
    if ($qrCodeBase64 !== '') {
        $html .= "<p>Escaneie o QR Code abaixo no app do seu banco:</p>";
        $html .= "<p><img src=\"cid:pixqrcode\" alt=\"QR Code Pix\" style=\"max-width:260px;\"></p>";
    }
    if ($qrCode !== '') {
        $html .= "<p>Ou copie e cole o código Pix:</p>";
        $html .= "<p style=\"background:#f4f4f4;padding:12px;border-radius:6px;word-break:break-all;font-family:monospace;font-size:12px;\">" . htmlspecialchars($qrCode) . "</p>";
    }
    $html .= "<p>O Pix é aprovado na hora. Assim que identificarmos o pagamento, você recebe a confirmação por email.</p>";

    if (!class_exists('PHPMailer\PHPMailer\PHPMailer')) {
        return false;
    }

    try {
        $mail = new \PHPMailer\PHPMailer\PHPMailer(true);
        $mail->isSMTP();
        $mail->Host = $config['smtp_host'];
        $mail->Port = $config['smtp_port'];
        $mail->SMTPSecure = $config['smtp_secure'];
        $mail->SMTPAuth = true;
        $mail->Timeout = 30;
        $mail->Username = $config['smtp_user'];
        $mail->Password = $config['smtp_pass'];
        $mail->setFrom($config['from_email'], $config['from_name']);
        $mail->addAddress($email);
        $mail->Subject = $subject;
        $mail->isHTML(true);
        $mail->Body = $html;
        $mail->CharSet = 'UTF-8';
        $mail->Encoding = '8bit';

        if ($qrCodeBase64 !== '') {
            $decoded = base64_decode($qrCodeBase64, true);
            if ($decoded !== false) {
                $mail->addStringEmbeddedImage($decoded, 'pixqrcode', 'pix-qrcode.png', 'base64', 'image/png');
            }
        }

        return $mail->send();
    } catch (Exception $e) {
        error_log('PHPMailer error (pix qr): ' . $e->getMessage());
        return false;
    }
}

function send_order_confirmation_email(
    string $email,
    string $name,
    string $order_id,
    string $order_total,
    array $items
): bool {
    $siteBaseUrl = sv_mailer_site_url();
    $items_html = '';
    foreach ($items as $item) {
        $items_html .= "<tr>";
        $items_html .= "<td>" . htmlspecialchars($item['name']) . "</td>";
        $items_html .= "<td style='text-align: center;'>" . $item['quantity'] . "</td>";
        $items_html .= "<td style='text-align: right;'>R$ " . number_format($item['price'], 2, ',', '.') . "</td>";
        $items_html .= "</tr>";
    }

    $subject = "Confirmação do Pedido #$order_id - ShopVivaliz";

    $html = "<h2>Oi $name!</h2>";
    $html .= "<p>Seu pedido foi confirmado com sucesso!</p>";
    $html .= "<p><strong>Número do Pedido:</strong> #$order_id</p>";
    $html .= "<p><strong>Itens:</strong></p>";
    $html .= "<table style='width: 100%; border-collapse: collapse;'>";
    $html .= "<tr><th style='text-align: left;'>Produto</th><th>Qtd</th><th>Preço</th></tr>";
    $html .= $items_html;
    $html .= "</table>";
    $html .= "<p style='margin-top: 20px;'><strong>Total: R$ $order_total</strong></p>";
    $html .= "<p><a href='{$siteBaseUrl}/meus-pedidos' style='background: #667eea; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px; display: inline-block;'>Acompanhar Pedido</a></p>";

    return send_email($email, $subject, $html);
}

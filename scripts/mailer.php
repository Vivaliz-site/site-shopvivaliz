<?php
/**
 * Central ShopVivaliz transactional mailer.
 *
 * Production is Brevo API only and fails closed when BREVO_API_KEY is absent.
 * Sender and Reply-To are fixed in code to prevent legacy identity drift.
 */

function sv_mailer_load_env(): void
{
    static $loaded = false;
    if ($loaded) {
        return;
    }
    $loaded = true;

    $envPath = dirname(__DIR__) . '/.env';
    if (!is_file($envPath) || !is_readable($envPath)) {
        return;
    }

    foreach (file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
            continue;
        }
        [$key, $value] = explode('=', $line, 2);
        $key = trim($key);
        $value = trim($value, " \t\n\r\0\x0B\"'");
        if ($key === '' || getenv($key) !== false) {
            continue;
        }
        putenv($key . '=' . $value);
        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
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
    sv_mailer_load_env();

    return [
        'provider' => 'brevo_api',
        'api_key' => trim((string)(getenv('BREVO_API_KEY') ?: '')),
        'endpoint' => 'https://api.brevo.com/v3/smtp/email',
        'from_email' => 'atendimento@shopvivaliz.com.br',
        'from_name' => 'ShopVivaliz',
        'reply_to_email' => 'atendimento@shopvivaliz.com.br',
        'reply_to_name' => 'ShopVivaliz Atendimento',
    ];
}

function sv_mailer_has_forbidden_brand_content(string ...$parts): bool
{
    $haystack = mb_strtolower(implode("\n", $parts), 'UTF-8');
    foreach ([
        'contabilidade melo',
        'contabilidademelo',
        'fiscalmelo',
        'naoresponda@dev.shopvivaliz.com.br',
    ] as $forbidden_brand_content) {
        if (str_contains($haystack, $forbidden_brand_content)) {
            return true;
        }
    }
    return false;
}

function sv_mailer_build_brevo_payload(
    string $to,
    string $subject,
    string $html,
    ?string $text = null,
    array $attachments = []
): array {
    $config = get_mailer_config();

    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
        throw new InvalidArgumentException('recipient_invalid');
    }
    if (sv_mailer_has_forbidden_brand_content($subject, $html, (string)$text)) {
        throw new RuntimeException('forbidden_brand_content');
    }

    $payload = [
        'sender' => ['name' => $config['from_name'], 'email' => $config['from_email']],
        'replyTo' => ['name' => $config['reply_to_name'], 'email' => $config['reply_to_email']],
        'to' => [['email' => $to]],
        'subject' => $subject,
        'htmlContent' => $html,
        'headers' => ['X-ShopVivaliz-Mailer' => 'transactional-v1'],
        'tags' => ['shopvivaliz-transactional'],
    ];
    if ($text !== null && trim($text) !== '') {
        $payload['textContent'] = $text;
    }

    if ($attachments !== []) {
        $payload['attachment'] = [];
        foreach ($attachments as $attachment) {
            $name = trim((string)($attachment['name'] ?? ''));
            $content = trim((string)($attachment['content'] ?? ''));
            if ($name === '' || $content === '') {
                throw new InvalidArgumentException('attachment_invalid');
            }
            $payload['attachment'][] = ['name' => $name, 'content' => $content];
        }
    }

    return $payload;
}

function send_email_with_result(
    string $to,
    string $subject,
    string $html,
    ?string $text = null,
    array $attachments = []
): array {
    $config = get_mailer_config();
    if ($config['api_key'] === '') {
        error_log('[ShopVivaliz Mail] BREVO_API_KEY missing; fail-closed.');
        return ['success' => false, 'error' => 'provider_not_configured'];
    }
    if (!function_exists('curl_init')) {
        error_log('[ShopVivaliz Mail] PHP cURL extension missing; fail-closed.');
        return ['success' => false, 'error' => 'curl_extension_missing'];
    }

    try {
        $payload = sv_mailer_build_brevo_payload($to, $subject, $html, $text, $attachments);
        $encoded = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        $ch = curl_init($config['endpoint']);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTPHEADER => [
                'accept: application/json',
                'content-type: application/json; charset=utf-8',
                'api-key: ' . $config['api_key'],
            ],
            CURLOPT_POSTFIELDS => $encoded,
        ]);
        $body = curl_exec($ch);
        $curlError = curl_error($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        if ($body === false || $status !== 201) {
            error_log('[ShopVivaliz Mail] Brevo send failed status=' . $status . ' transport=' . ($curlError !== '' ? 'curl_error' : 'http_error'));
            return ['success' => false, 'error' => 'provider_send_failed', 'status_code' => $status];
        }

        $decoded = json_decode((string)$body, true);
        $messageId = is_array($decoded) ? trim((string)($decoded['messageId'] ?? '')) : '';
        if ($messageId === '') {
            error_log('[ShopVivaliz Mail] Brevo response missing messageId.');
            return ['success' => false, 'error' => 'provider_response_invalid', 'status_code' => $status];
        }

        return ['success' => true, 'message_id' => $messageId, 'status_code' => $status];
    } catch (Throwable $e) {
        $kind = $e instanceof RuntimeException && $e->getMessage() === 'forbidden_brand_content'
            ? 'forbidden_brand_content'
            : 'provider_exception';
        error_log('[ShopVivaliz Mail] send blocked/failure class=' . $kind);
        return ['success' => false, 'error' => $kind];
    }
}

function send_email(
    string $to,
    string $subject,
    string $html,
    ?string $text = null,
    array $attachments = []
): bool {
    return (bool)(send_email_with_result($to, $subject, $html, $text, $attachments)['success'] ?? false);
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
    $totalFmt = number_format($total, 2, ',', '.');
    $subject = "Pague com Pix - Pedido $orderNumber - ShopVivaliz";

    $html = "<h2>Oi " . htmlspecialchars($name) . ",</h2>";
    $html .= "<p>Recebemos seu pedido <strong>#" . htmlspecialchars($orderNumber) . "</strong>! Falta só o pagamento via Pix para confirmarmos.</p>";
    $html .= "<p><strong>Valor:</strong> R$ $totalFmt</p>";
    if ($qrCodeBase64 !== '') {
        $html .= "<p>O QR Code Pix está anexado a este email como <strong>pix-qrcode.png</strong>.</p>";
    }
    if ($qrCode !== '') {
        $html .= "<p>Ou copie e cole o código Pix:</p>";
        $html .= "<p style=\"background:#f4f4f4;padding:12px;border-radius:6px;word-break:break-all;font-family:monospace;font-size:12px;\">" . htmlspecialchars($qrCode) . "</p>";
    }
    $html .= "<p>O Pix é aprovado na hora. Assim que identificarmos o pagamento, você recebe a confirmação por email.</p>";

    $attachments = [];
    if ($qrCodeBase64 !== '' && base64_decode($qrCodeBase64, true) !== false) {
        $attachments[] = ['name' => 'pix-qrcode.png', 'content' => $qrCodeBase64];
    }

    return send_email($email, $subject, $html, null, $attachments);
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

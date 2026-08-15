<?php
declare(strict_types=1);

require_once __DIR__ . '/coupons.php';

const SVFPR_DISCOUNT_PERCENT = 5.0;
const SVFPR_VALID_DAYS = 30;
const SVFPR_REASON = 'first_paid_purchase';

function svfpr_generate_code(): string
{
    return 'VIVA5-' . strtoupper(substr(bin2hex(random_bytes(5)), 0, 10));
}

function svfpr_has_prior_paid_order(string $email, string $currentOrder): bool
{
    try {
        $stmt = sv_pdo()->prepare(
            "SELECT 1 FROM orders
             WHERE LOWER(email) = :email
               AND order_number <> :order_number
               AND LOWER(order_status) IN (
                   'pagamento_aprovado','payment_approved','aprovado','paid','completed',
                   'nota_fiscal_enviada','pronto_para_enviar','enviado','entregue'
               )
             LIMIT 1"
        );
        $stmt->execute([':email' => $email, ':order_number' => $currentOrder]);
        return (bool)$stmt->fetchColumn();
    } catch (Throwable $e) {
        error_log('[FirstPurchaseReward] prior paid lookup failed: ' . $e->getMessage());
        return true;
    }
}

function svfpr_email_subject(): string
{
    return '🎁 Você ganhou 5% OFF na sua próxima compra | ShopVivaliz';
}

function svfpr_email_html(string $name, string $code, string $expiresAt): string
{
    $esc = static fn(string $v): string => htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $safeName = $esc($name);
    $safeCode = $esc($code);
    $expiresLabel = date('d/m/Y', strtotime($expiresAt));
    $shopUrl = rtrim((string)(getenv('SHOPVIVALIZ_BASE_URL') ?: getenv('APP_URL') ?: 'https://shopvivaliz.com.br'), '/');

    return '<!doctype html><html lang="pt-BR"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Seu presente ShopVivaliz</title></head>'
        . '<body style="margin:0;background:#f4f7fb;font-family:Arial,sans-serif;color:#17324d">'
        . '<div style="max-width:620px;margin:0 auto;padding:28px 16px">'
        . '<div style="background:linear-gradient(135deg,#173B63,#0f8f62);border-radius:20px 20px 0 0;padding:34px 28px;text-align:center;color:#fff">'
        . '<div style="font-size:40px">🎁</div><h1 style="margin:8px 0 6px;font-size:28px">Sua primeira compra valeu um presente!</h1>'
        . '<p style="margin:0;opacity:.95;font-size:16px">Obrigado por escolher a ShopVivaliz.</p></div>'
        . '<div style="background:#fff;padding:30px 28px;border-radius:0 0 20px 20px;border:1px solid #dfe7ef;border-top:0">'
        . '<p style="font-size:16px">Olá, <strong>' . $safeName . '</strong>!</p>'
        . '<p style="font-size:16px;line-height:1.65">Como agradecimento por realizar sua <strong>primeira compra com pagamento aprovado</strong>, você ganhou um cupom exclusivo de <strong>5% OFF</strong> para usar na próxima compra.</p>'
        . '<div style="margin:26px 0;padding:22px;background:#f0fdf4;border:2px dashed #0f8f62;border-radius:16px;text-align:center">'
        . '<div style="font-size:13px;font-weight:700;color:#46627a;text-transform:uppercase;letter-spacing:.08em">Seu cupom exclusivo</div>'
        . '<div style="font-size:30px;font-weight:900;letter-spacing:.05em;color:#07345d;margin:8px 0">' . $safeCode . '</div>'
        . '<div style="font-size:14px;color:#0f7a55"><strong>5% de desconto</strong> • válido até ' . $esc($expiresLabel) . '</div></div>'
        . '<p style="font-size:14px;line-height:1.6;color:#52687c">O cupom é pessoal, válido por 30 dias e pode ser utilizado uma única vez. Em cada pedido é permitido aplicar apenas um cupom.</p>'
        . '<p style="text-align:center;margin:28px 0 8px"><a href="' . $esc($shopUrl) . '/catalogo" style="display:inline-block;background:#173B63;color:#fff;text-decoration:none;font-weight:800;padding:14px 24px;border-radius:12px">Escolher minha próxima compra</a></p>'
        . '<p style="font-size:12px;color:#7a8b9a;text-align:center;margin-top:26px">ShopVivaliz • benefício concedido automaticamente após a confirmação da primeira compra.</p>'
        . '</div></div></body></html>';
}

function svfpr_send_reward_email(string $email, string $name, string $code, string $expiresAt): bool
{
    try {
        require_once dirname(__DIR__) . '/scripts/mailer.php';
        return send_email($email, svfpr_email_subject(), svfpr_email_html($name, $code, $expiresAt));
    } catch (Throwable $e) {
        error_log('[FirstPurchaseReward] email failed: ' . $e->getMessage());
        return false;
    }
}

/** @return array{created:bool,code:string,expires_at:string,email_sent:bool,eligible:bool} */
function svfpr_issue_for_approved_order(array $order): array
{
    $email = svcp_normalize_email((string)($order['customer']['email'] ?? ''));
    $name = trim((string)($order['customer']['name'] ?? 'Cliente')) ?: 'Cliente';
    $orderNumber = trim((string)($order['order_number'] ?? ''));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || $orderNumber === '') {
        return ['created'=>false,'code'=>'','expires_at'=>'','email_sent'=>false,'eligible'=>false];
    }

    svcp_ensure_schema();
    if (svfpr_has_prior_paid_order($email, $orderNumber)) {
        return ['created'=>false,'code'=>'','expires_at'=>'','email_sent'=>false,'eligible'=>false];
    }

    $existing = sv_pdo()->prepare('SELECT code, expires_at, email_sent_at FROM coupons WHERE owner_email = :email AND issued_reason = :reason ORDER BY id ASC LIMIT 1');
    $existing->execute([':email'=>$email, ':reason'=>SVFPR_REASON]);
    $row = $existing->fetch();
    if (is_array($row)) {
        $code = (string)$row['code'];
        $expiresAt = (string)($row['expires_at'] ?? '');
        $sent = !empty($row['email_sent_at']);
        if (!$sent && $expiresAt !== '' && strtotime($expiresAt) >= time()) {
            $sent = svfpr_send_reward_email($email, $name, $code, $expiresAt);
            if ($sent) svcp_mark_email_sent($code);
        }
        return ['created'=>false,'code'=>$code,'expires_at'=>$expiresAt,'email_sent'=>$sent,'eligible'=>true];
    }

    $expiresAt = date('Y-m-d H:i:s', time() + SVFPR_VALID_DAYS * 86400);
    $issued = null;
    for ($attempt = 0; $attempt < 5; $attempt++) {
        try {
            $issued = svcp_issue([
                'code' => svfpr_generate_code(),
                'description' => 'Presente da primeira compra: 5% OFF por 30 dias',
                'discount_type' => 'percent',
                'discount_value' => SVFPR_DISCOUNT_PERCENT,
                'min_order_value' => 0,
                'starts_at' => date('Y-m-d H:i:s'),
                'ends_at' => $expiresAt,
                'expires_at' => $expiresAt,
                'max_uses' => 1,
                'display_in_navbar' => 0,
                'display_in_popup' => 0,
                'owner_email' => $email,
                'source_order' => $orderNumber,
                'issued_reason' => SVFPR_REASON,
            ]);
            break;
        } catch (Throwable $e) {
            if ($attempt === 4) {
                error_log('[FirstPurchaseReward] issue failed order=' . $orderNumber . ' ' . $e->getMessage());
            }
        }
    }

    if (!is_array($issued) || empty($issued['code'])) {
        return ['created'=>false,'code'=>'','expires_at'=>'','email_sent'=>false,'eligible'=>false];
    }

    $code = (string)$issued['code'];
    $expiresAt = (string)($issued['expires_at'] ?? $expiresAt);
    $emailSent = !empty($issued['email_sent']);
    if (!$emailSent) {
        $emailSent = svfpr_send_reward_email($email, $name, $code, $expiresAt);
        if ($emailSent) svcp_mark_email_sent($code);
    }

    return ['created'=>(bool)($issued['created'] ?? false),'code'=>$code,'expires_at'=>$expiresAt,'email_sent'=>$emailSent,'eligible'=>true];
}

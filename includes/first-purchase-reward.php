<?php
declare(strict_types=1);

require_once __DIR__ . '/pdo-database.php';

const SVFPR_DISCOUNT_PERCENT = 5.0;
const SVFPR_VALID_DAYS = 30;

function svfpr_normalize_email(string $email): string
{
    return strtolower(trim($email));
}

function svfpr_ensure_schema(): void
{
    static $done = false;
    if ($done) return;
    $done = true;

    $pdo = sv_pdo();
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS customer_reward_coupons (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            customer_email VARCHAR(190) NOT NULL,
            coupon_code VARCHAR(80) NOT NULL,
            source_order VARCHAR(80) NOT NULL,
            issued_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            expires_at DATETIME NOT NULL,
            email_sent_at DATETIME NULL,
            redeemed_order VARCHAR(80) NULL,
            redeemed_at DATETIME NULL,
            UNIQUE KEY uq_reward_customer_email (customer_email),
            UNIQUE KEY uq_reward_coupon_code (coupon_code),
            UNIQUE KEY uq_reward_source_order (source_order),
            KEY idx_reward_expires_at (expires_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
    );
}

function svfpr_generate_code(): string
{
    return 'VIVA5-' . strtoupper(substr(bin2hex(random_bytes(5)), 0, 10));
}

/**
 * Cria exatamente um cupom de recompensa por e-mail de cliente.
 * A UNIQUE(customer_email) transforma webhooks repetidos em operacao idempotente.
 *
 * @return array{created:bool,code:string,expires_at:string,email_sent:bool}
 */
function svfpr_issue_for_approved_order(array $order): array
{
    $email = svfpr_normalize_email((string)($order['customer']['email'] ?? ''));
    $name = trim((string)($order['customer']['name'] ?? 'Cliente')) ?: 'Cliente';
    $orderNumber = trim((string)($order['order_number'] ?? ''));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || $orderNumber === '') {
        return ['created' => false, 'code' => '', 'expires_at' => '', 'email_sent' => false];
    }

    svfpr_ensure_schema();
    $pdo = sv_pdo();

    // Se o cliente ja recebeu a recompensa, nao cria nem reenvia outra.
    $existing = $pdo->prepare('SELECT coupon_code, expires_at, email_sent_at FROM customer_reward_coupons WHERE customer_email = :email LIMIT 1');
    $existing->execute([':email' => $email]);
    $row = $existing->fetch();
    if (is_array($row)) {
        return [
            'created' => false,
            'code' => (string)($row['coupon_code'] ?? ''),
            'expires_at' => (string)($row['expires_at'] ?? ''),
            'email_sent' => !empty($row['email_sent_at']),
        ];
    }

    $expiresAt = date('Y-m-d H:i:s', time() + SVFPR_VALID_DAYS * 86400);
    $code = '';

    for ($attempt = 0; $attempt < 5; $attempt++) {
        $candidate = svfpr_generate_code();
        try {
            $pdo->beginTransaction();

            $coupon = $pdo->prepare(
                'INSERT INTO coupons
                    (code, description, discount_type, discount_value, min_order_value, starts_at, ends_at, expires_at, max_uses, used_count, is_active)
                 VALUES
                    (:code, :description, :type, :value, 0, NOW(), :expires_at, :expires_at, 1, 0, 1)'
            );
            $coupon->execute([
                ':code' => $candidate,
                ':description' => 'Presente da primeira compra: 5% OFF por 30 dias',
                ':type' => 'percent',
                ':value' => SVFPR_DISCOUNT_PERCENT,
                ':expires_at' => $expiresAt,
            ]);

            $reward = $pdo->prepare(
                'INSERT INTO customer_reward_coupons (customer_email, coupon_code, source_order, expires_at)
                 VALUES (:email, :code, :order_number, :expires_at)'
            );
            $reward->execute([
                ':email' => $email,
                ':code' => $candidate,
                ':order_number' => $orderNumber,
                ':expires_at' => $expiresAt,
            ]);

            $pdo->commit();
            $code = $candidate;
            break;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();

            // Webhooks concorrentes podem chegar simultaneamente. Se outro
            // processo venceu a corrida pela UNIQUE(customer_email), retornamos
            // o cupom ja criado e nao geramos um segundo beneficio.
            $check = $pdo->prepare('SELECT coupon_code, expires_at, email_sent_at FROM customer_reward_coupons WHERE customer_email = :email LIMIT 1');
            $check->execute([':email' => $email]);
            $won = $check->fetch();
            if (is_array($won)) {
                return [
                    'created' => false,
                    'code' => (string)($won['coupon_code'] ?? ''),
                    'expires_at' => (string)($won['expires_at'] ?? ''),
                    'email_sent' => !empty($won['email_sent_at']),
                ];
            }
            if ($attempt === 4) {
                error_log('[FirstPurchaseReward] issue failed order=' . $orderNumber . ' ' . $e->getMessage());
                return ['created' => false, 'code' => '', 'expires_at' => '', 'email_sent' => false];
            }
        }
    }

    if ($code === '') {
        return ['created' => false, 'code' => '', 'expires_at' => '', 'email_sent' => false];
    }

    $emailSent = false;
    try {
        require_once dirname(__DIR__) . '/scripts/mailer.php';
        $emailSent = send_email($email, svfpr_email_subject(), svfpr_email_html($name, $code, $expiresAt));
        if ($emailSent) {
            $mark = $pdo->prepare('UPDATE customer_reward_coupons SET email_sent_at = NOW() WHERE customer_email = :email AND coupon_code = :code');
            $mark->execute([':email' => $email, ':code' => $code]);
        }
    } catch (Throwable $e) {
        error_log('[FirstPurchaseReward] email failed order=' . $orderNumber . ' ' . $e->getMessage());
    }

    return ['created' => true, 'code' => $code, 'expires_at' => $expiresAt, 'email_sent' => $emailSent];
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
        . '<p style="font-size:16px;line-height:1.65">Como agradecimento por realizar sua <strong>primeira compra</strong>, você ganhou um cupom exclusivo de <strong>5% OFF</strong> para usar na próxima compra.</p>'
        . '<div style="margin:26px 0;padding:22px;background:#f0fdf4;border:2px dashed #0f8f62;border-radius:16px;text-align:center">'
        . '<div style="font-size:13px;font-weight:700;color:#46627a;text-transform:uppercase;letter-spacing:.08em">Seu cupom exclusivo</div>'
        . '<div style="font-size:30px;font-weight:900;letter-spacing:.05em;color:#07345d;margin:8px 0">' . $safeCode . '</div>'
        . '<div style="font-size:14px;color:#0f7a55"><strong>5% de desconto</strong> • válido até ' . $esc($expiresLabel) . '</div></div>'
        . '<p style="font-size:14px;line-height:1.6;color:#52687c">O cupom é pessoal, válido por 30 dias e pode ser utilizado uma única vez. Em cada pedido é permitido aplicar apenas um cupom.</p>'
        . '<p style="text-align:center;margin:28px 0 8px"><a href="' . $esc($shopUrl) . '/catalogo" style="display:inline-block;background:#173B63;color:#fff;text-decoration:none;font-weight:800;padding:14px 24px;border-radius:12px">Escolher minha próxima compra</a></p>'
        . '<p style="font-size:12px;color:#7a8b9a;text-align:center;margin-top:26px">ShopVivaliz • benefício concedido automaticamente após a confirmação da primeira compra.</p>'
        . '</div></div></body></html>';
}

function svfpr_coupon_owner(string $code): string
{
    if ($code === '') return '';
    try {
        svfpr_ensure_schema();
        $stmt = sv_pdo()->prepare('SELECT customer_email FROM customer_reward_coupons WHERE coupon_code = :code LIMIT 1');
        $stmt->execute([':code' => strtoupper(trim($code))]);
        return svfpr_normalize_email((string)($stmt->fetchColumn() ?: ''));
    } catch (Throwable $e) {
        error_log('[FirstPurchaseReward] owner lookup failed: ' . $e->getMessage());
        return '';
    }
}

function svfpr_mark_coupon_redeemed(string $code, string $email, string $orderNumber): void
{
    $code = strtoupper(trim($code));
    $email = svfpr_normalize_email($email);
    if ($code === '' || $email === '' || $orderNumber === '') return;

    try {
        svfpr_ensure_schema();
        $pdo = sv_pdo();
        $pdo->beginTransaction();
        $stmt = $pdo->prepare(
            'UPDATE customer_reward_coupons
             SET redeemed_order = :order_number, redeemed_at = NOW()
             WHERE coupon_code = :code AND customer_email = :email AND redeemed_at IS NULL'
        );
        $stmt->execute([':order_number' => $orderNumber, ':code' => $code, ':email' => $email]);
        if ($stmt->rowCount() > 0) {
            $use = $pdo->prepare('UPDATE coupons SET used_count = used_count + 1, is_active = 0 WHERE code = :code AND used_count < max_uses');
            $use->execute([':code' => $code]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) $pdo->rollBack();
        error_log('[FirstPurchaseReward] redeem failed order=' . $orderNumber . ' ' . $e->getMessage());
    }
}

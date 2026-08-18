<?php
declare(strict_types=1);

require_once __DIR__ . '/account-schema.php';

function svcp_normalize_email(string $email): string
{
    return strtolower(trim($email));
}

function svcp_ensure_schema(): void
{
    static $done = false;
    if ($done) return;
    $done = true;

    sv_account_ensure_schema();
    $pdo = sv_pdo();

    $columns = [];
    foreach ($pdo->query('SHOW COLUMNS FROM coupons')->fetchAll() as $row) {
        $columns[(string)$row['Field']] = true;
    }
    $alterations = [
        'owner_email' => 'ALTER TABLE coupons ADD COLUMN owner_email VARCHAR(190) NULL',
        'source_order' => 'ALTER TABLE coupons ADD COLUMN source_order VARCHAR(80) NULL',
        'issued_reason' => 'ALTER TABLE coupons ADD COLUMN issued_reason VARCHAR(60) NULL',
        'email_sent_at' => 'ALTER TABLE coupons ADD COLUMN email_sent_at DATETIME NULL',
        'redeemed_order' => 'ALTER TABLE coupons ADD COLUMN redeemed_order VARCHAR(80) NULL',
        'redeemed_at' => 'ALTER TABLE coupons ADD COLUMN redeemed_at DATETIME NULL',
    ];
    foreach ($alterations as $column => $sql) {
        if (!isset($columns[$column])) {
            $pdo->exec($sql);
            $columns[$column] = true;
        }
    }

    $indexes = [];
    foreach ($pdo->query('SHOW INDEX FROM coupons')->fetchAll() as $row) {
        $indexes[(string)$row['Key_name']] = true;
    }
    if (!isset($indexes['idx_coupon_owner_reason'])) {
        $pdo->exec('ALTER TABLE coupons ADD INDEX idx_coupon_owner_reason (owner_email, issued_reason)');
    }
    if (!isset($indexes['idx_coupon_source_order'])) {
        $pdo->exec('ALTER TABLE coupons ADD INDEX idx_coupon_source_order (source_order)');
    }

    // Migração única da estrutura experimental anterior para o motor oficial.
    // Depois da cópia dos metadados, a tabela paralela é eliminada.
    $legacy = $pdo->query("SHOW TABLES LIKE 'customer_reward_coupons'")->fetchColumn();
    if ($legacy) {
        $pdo->exec(
            "UPDATE coupons c
             INNER JOIN customer_reward_coupons r ON r.coupon_code = c.code
             SET c.owner_email = COALESCE(c.owner_email, r.customer_email),
                 c.source_order = COALESCE(c.source_order, r.source_order),
                 c.issued_reason = COALESCE(c.issued_reason, 'first_paid_purchase'),
                 c.email_sent_at = COALESCE(c.email_sent_at, r.email_sent_at),
                 c.redeemed_order = COALESCE(c.redeemed_order, r.redeemed_order),
                 c.redeemed_at = COALESCE(c.redeemed_at, r.redeemed_at)"
        );
        $pdo->exec('DROP TABLE customer_reward_coupons');
    }
}

/**
 * Validação server-side de cupons da tabela única `coupons`.
 * O terceiro argumento é opcional para pré-visualização; a criação do pedido
 * sempre envia o e-mail para validar cupons pessoais.
 *
 * @return array{ok: bool, code: string, percent: float, amount: float, label: string, type: string, error: string}
 */
function svcp_validate(string $rawCode, float $itemsSubtotal, string $customerEmail = ''): array
{
    $code = strtoupper(trim($rawCode));
    $fail = static fn(string $error, string $c = ''): array => ['ok'=>false,'code'=>$c,'percent'=>0.0,'amount'=>0.0,'label'=>'','type'=>'','error'=>$error];
    if ($code === '') return $fail('coupon_empty');
    if ($itemsSubtotal <= 0) return $fail('coupon_empty_cart', $code);
    if (strlen($code) > 80 || preg_match('/^[A-Z0-9_-]+$/', $code) !== 1) return $fail('coupon_invalid', $code);

    try {
        svcp_ensure_schema();
        $stmt = sv_pdo()->prepare(
            'SELECT code, description, discount_type, discount_value, min_order_value,
                    starts_at, ends_at, expires_at, max_uses, used_count, is_active,
                    owner_email, redeemed_at
             FROM coupons WHERE code = :code LIMIT 1'
        );
        $stmt->execute([':code' => $code]);
        $row = $stmt->fetch();
    } catch (Throwable $e) {
        error_log('[coupons] lookup failed: ' . $e->getMessage());
        return $fail('coupon_lookup_failed', $code);
    }

    if (!is_array($row)) return $fail('coupon_invalid', $code);
    if ((int)($row['is_active'] ?? 0) !== 1) return $fail('coupon_inactive', $code);

    $owner = svcp_normalize_email((string)($row['owner_email'] ?? ''));
    $normalizedEmail = svcp_normalize_email($customerEmail);
    if ($owner !== '' && $normalizedEmail !== '' && !hash_equals($owner, $normalizedEmail)) {
        return $fail('coupon_customer_mismatch', $code);
    }
    if ($owner !== '' && !empty($row['redeemed_at'])) return $fail('coupon_exhausted', $code);

    $startsAt = trim((string)($row['starts_at'] ?? ''));
    $endsAt = trim((string)($row['ends_at'] ?? ''));
    $expiresAt = trim((string)($row['expires_at'] ?? ''));
    $now = time();
    if ($startsAt !== '' && strtotime($startsAt) > $now) return $fail('coupon_not_started', $code);
    $effectiveExpiry = $expiresAt !== '' ? $expiresAt : $endsAt;
    if ($effectiveExpiry !== '' && strtotime($effectiveExpiry) < $now) return $fail('coupon_expired', $code);

    $maxUses = (int)($row['max_uses'] ?? 0);
    $usedCount = (int)($row['used_count'] ?? 0);
    if ($maxUses > 0 && $usedCount >= $maxUses) return $fail('coupon_exhausted', $code);

    $minOrderValue = (float)($row['min_order_value'] ?? 0);
    if ($minOrderValue > 0 && $itemsSubtotal < $minOrderValue) return $fail('coupon_min_order', $code);

    $type = (string)($row['discount_type'] ?? 'percent');
    $value = (float)($row['discount_value'] ?? 0);
    $label = trim((string)($row['description'] ?? '')) ?: svcp_default_label($type, $value);
    $percent = 0.0;
    $amount = 0.0;
    if ($type === 'percent') {
        $percent = $value;
        $amount = round($itemsSubtotal * $value / 100, 2);
    } elseif ($type === 'fixed') {
        $amount = round(min($value, $itemsSubtotal), 2);
    } elseif ($type === 'shipping') {
        // Rodada 2 (2026-08-18): nenhum código deste motor zera o frete de
        // fato -- o total do pedido em api/orders/process-validated.php soma
        // shippingTotal independente do cupom, e sv_active_coupon_offer_text()
        // (includes/active-coupons.php) anunciava "FRETE GRÁTIS" na navbar
        // para este tipo mesmo assim. Hoje não há cupom shipping ativo, então
        // isto é uma proteção preventiva: recusar explicitamente em vez de
        // aceitar com desconto zero, até que a feature de frete grátis seja
        // implementada de verdade (decisão de negócio pendente com o Fred —
        // ver B8 no relatório da Rodada 2).
        return ['ok'=>false,'code'=>$code,'percent'=>0.0,'amount'=>0.0,'label'=>'','type'=>$type,'error'=>'coupon_unsupported_type'];
    } else {
        return ['ok'=>false,'code'=>$code,'percent'=>0.0,'amount'=>0.0,'label'=>'','type'=>$type,'error'=>'coupon_unsupported_type'];
    }

    return ['ok'=>true,'code'=>(string)$row['code'],'percent'=>$percent,'amount'=>$amount,'label'=>$label,'type'=>$type,'error'=>''];
}

/**
 * Emite qualquer cupom pelo mesmo motor. Para campanhas pessoais, owner_email
 * + issued_reason também funcionam como chave lógica contra emissão duplicada.
 */
function svcp_issue(array $spec): array
{
    svcp_ensure_schema();
    $pdo = sv_pdo();
    $code = strtoupper(trim((string)($spec['code'] ?? '')));
    $owner = svcp_normalize_email((string)($spec['owner_email'] ?? ''));
    $reason = trim((string)($spec['issued_reason'] ?? ''));
    if ($code === '' || preg_match('/^[A-Z0-9_-]+$/', $code) !== 1) {
        throw new InvalidArgumentException('invalid_coupon_code');
    }

    if ($owner !== '' && $reason !== '') {
        $existing = $pdo->prepare('SELECT code, expires_at, email_sent_at FROM coupons WHERE owner_email = :owner AND issued_reason = :reason ORDER BY id ASC LIMIT 1');
        $existing->execute([':owner' => $owner, ':reason' => $reason]);
        $row = $existing->fetch();
        if (is_array($row)) {
            return ['created'=>false,'code'=>(string)$row['code'],'expires_at'=>(string)($row['expires_at'] ?? ''),'email_sent'=>!empty($row['email_sent_at'])];
        }
    }

    $stmt = $pdo->prepare(
        'INSERT INTO coupons
            (code, description, discount_type, discount_value, min_order_value,
             starts_at, ends_at, expires_at, max_uses, used_count, is_active,
             display_in_navbar, display_in_popup, owner_email, source_order,
             issued_reason, email_sent_at, redeemed_order, redeemed_at)
         VALUES
            (:code, :description, :discount_type, :discount_value, :min_order_value,
             :starts_at, :ends_at, :expires_at, :max_uses, 0, 1,
             :display_in_navbar, :display_in_popup, :owner_email, :source_order,
             :issued_reason, NULL, NULL, NULL)'
    );
    $stmt->execute([
        ':code' => $code,
        ':description' => (string)($spec['description'] ?? ''),
        ':discount_type' => (string)($spec['discount_type'] ?? 'percent'),
        ':discount_value' => round((float)($spec['discount_value'] ?? 0), 2),
        ':min_order_value' => round((float)($spec['min_order_value'] ?? 0), 2),
        ':starts_at' => $spec['starts_at'] ?? null,
        ':ends_at' => $spec['ends_at'] ?? null,
        ':expires_at' => $spec['expires_at'] ?? null,
        ':max_uses' => max(0, (int)($spec['max_uses'] ?? 0)),
        ':display_in_navbar' => !empty($spec['display_in_navbar']) ? 1 : 0,
        ':display_in_popup' => !empty($spec['display_in_popup']) ? 1 : 0,
        ':owner_email' => $owner !== '' ? $owner : null,
        ':source_order' => trim((string)($spec['source_order'] ?? '')) ?: null,
        ':issued_reason' => $reason !== '' ? $reason : null,
    ]);

    return ['created'=>true,'code'=>$code,'expires_at'=>(string)($spec['expires_at'] ?? ''),'email_sent'=>false];
}

function svcp_mark_email_sent(string $code): void
{
    if ($code === '') return;
    svcp_ensure_schema();
    $stmt = sv_pdo()->prepare('UPDATE coupons SET email_sent_at = COALESCE(email_sent_at, NOW()), updated_at = NOW() WHERE code = :code');
    $stmt->execute([':code' => strtoupper(trim($code))]);
}

function svcp_mark_redeemed(string $code, string $customerEmail, string $orderNumber): void
{
    $code = strtoupper(trim($code));
    if ($code === '' || $orderNumber === '') return;
    svcp_ensure_schema();
    $pdo = sv_pdo();
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('SELECT owner_email, max_uses, used_count, redeemed_at FROM coupons WHERE code = :code FOR UPDATE');
        $stmt->execute([':code' => $code]);
        $row = $stmt->fetch();
        if (!is_array($row)) {
            $pdo->rollBack();
            return;
        }
        $owner = svcp_normalize_email((string)($row['owner_email'] ?? ''));
        $email = svcp_normalize_email($customerEmail);
        if ($owner !== '' && ($email === '' || !hash_equals($owner, $email))) {
            $pdo->rollBack();
            return;
        }
        $maxUses = (int)($row['max_uses'] ?? 0);
        $used = (int)($row['used_count'] ?? 0);
        if (($maxUses > 0 && $used >= $maxUses) || ($owner !== '' && !empty($row['redeemed_at']))) {
            $pdo->rollBack();
            return;
        }

        $newUsed = $used + 1;
        $deactivate = $maxUses > 0 && $newUsed >= $maxUses ? 0 : 1;
        $update = $pdo->prepare(
            'UPDATE coupons
             SET used_count = :used_count,
                 is_active = :is_active,
                 redeemed_order = CASE WHEN owner_email IS NOT NULL THEN :order_number ELSE redeemed_order END,
                 redeemed_at = CASE WHEN owner_email IS NOT NULL THEN NOW() ELSE redeemed_at END,
                 updated_at = NOW()
             WHERE code = :code'
        );
        $update->execute([':used_count'=>$newUsed, ':is_active'=>$deactivate, ':order_number'=>$orderNumber, ':code'=>$code]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('[coupons] redeem failed order=' . $orderNumber . ' ' . $e->getMessage());
    }
}

function svcp_default_label(string $type, float $value): string
{
    return match ($type) {
        'percent' => 'Desconto ' . rtrim(rtrim(number_format($value, 2, ',', '.'), '0'), ',') . '%',
        'fixed' => 'Desconto R$ ' . number_format($value, 2, ',', '.'),
        'shipping' => 'Frete grátis',
        default => 'Desconto aplicado',
    };
}

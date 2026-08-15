<?php
declare(strict_types=1);

require_once __DIR__ . '/pdo-database.php';
require_once __DIR__ . '/first-purchase-reward.php';

/**
 * Validação server-side de cupons da tabela `coupons`.
 * O terceiro argumento é opcional para permitir pré-visualização no carrinho;
 * na criação do pedido o e-mail é obrigatório para cupons pessoais.
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
        $pdo = sv_pdo();
        $stmt = $pdo->prepare(
            'SELECT code, description, discount_type, discount_value, min_order_value, starts_at, ends_at, expires_at, max_uses, used_count, is_active
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

    // Cupons de recompensa da primeira compra são pessoais. O carrinho pode
    // consultar sem e-mail para mostrar a estimativa; a criação do pedido
    // sempre passa o e-mail e bloqueia uso por outra pessoa.
    $owner = svfpr_coupon_owner($code);
    $normalizedEmail = svfpr_normalize_email($customerEmail);
    if ($owner !== '' && $normalizedEmail !== '' && !hash_equals($owner, $normalizedEmail)) {
        return $fail('coupon_customer_mismatch', $code);
    }

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
        $amount = 0.0;
    } else {
        return ['ok'=>false,'code'=>$code,'percent'=>0.0,'amount'=>0.0,'label'=>'','type'=>$type,'error'=>'coupon_unsupported_type'];
    }

    return ['ok'=>true,'code'=>(string)$row['code'],'percent'=>$percent,'amount'=>$amount,'label'=>$label,'type'=>$type,'error'=>''];
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

<?php
declare(strict_types=1);

/**
 * Validação server-side de cupons da tabela `coupons`.
 * `discount_type` aceita: percent, fixed, shipping.
 */

require_once __DIR__ . '/pdo-database.php';

/**
 * @return array{ok: bool, code: string, percent: float, amount: float, label: string, type: string, error: string}
 */
function svcp_validate(string $rawCode, float $itemsSubtotal): array
{
    $code = strtoupper(trim($rawCode));
    if ($code === '') {
        return ['ok' => false, 'code' => '', 'percent' => 0.0, 'amount' => 0.0, 'label' => '', 'type' => '', 'error' => 'coupon_empty'];
    }
    if ($itemsSubtotal <= 0) {
        return ['ok' => false, 'code' => $code, 'percent' => 0.0, 'amount' => 0.0, 'label' => '', 'type' => '', 'error' => 'coupon_empty_cart'];
    }

    try {
        $pdo = sv_pdo();
        $stmt = $pdo->prepare(
            'SELECT code, description, discount_type, discount_value, min_order_value, starts_at, ends_at, expires_at, max_uses, used_count, is_active
             FROM coupons
             WHERE code = :code
             LIMIT 1'
        );
        $stmt->execute([':code' => $code]);
        $row = $stmt->fetch();
    } catch (Throwable $e) {
        error_log('[coupons] lookup failed: ' . $e->getMessage());
        return ['ok' => false, 'code' => $code, 'percent' => 0.0, 'amount' => 0.0, 'label' => '', 'type' => '', 'error' => 'coupon_lookup_failed'];
    }

    if (!is_array($row)) {
        return ['ok' => false, 'code' => $code, 'percent' => 0.0, 'amount' => 0.0, 'label' => '', 'type' => '', 'error' => 'coupon_invalid'];
    }

    if ((int)($row['is_active'] ?? 0) !== 1) {
        return ['ok' => false, 'code' => $code, 'percent' => 0.0, 'amount' => 0.0, 'label' => '', 'type' => '', 'error' => 'coupon_inactive'];
    }

    $startsAt = trim((string)($row['starts_at'] ?? ''));
    $endsAt = trim((string)($row['ends_at'] ?? ''));
    $expiresAt = trim((string)($row['expires_at'] ?? ''));
    $now = time();

    if ($startsAt !== '' && strtotime($startsAt) > $now) {
        return ['ok' => false, 'code' => $code, 'percent' => 0.0, 'amount' => 0.0, 'label' => '', 'type' => '', 'error' => 'coupon_not_started'];
    }

    $effectiveExpiry = $expiresAt !== '' ? $expiresAt : $endsAt;
    if ($effectiveExpiry !== '' && strtotime($effectiveExpiry) < $now) {
        return ['ok' => false, 'code' => $code, 'percent' => 0.0, 'amount' => 0.0, 'label' => '', 'type' => '', 'error' => 'coupon_expired'];
    }

    $maxUses = (int)($row['max_uses'] ?? 0);
    $usedCount = (int)($row['used_count'] ?? 0);
    if ($maxUses > 0 && $usedCount >= $maxUses) {
        return ['ok' => false, 'code' => $code, 'percent' => 0.0, 'amount' => 0.0, 'label' => '', 'type' => '', 'error' => 'coupon_exhausted'];
    }

    $minOrderValue = (float)($row['min_order_value'] ?? 0);
    if ($minOrderValue > 0 && $itemsSubtotal < $minOrderValue) {
        return ['ok' => false, 'code' => $code, 'percent' => 0.0, 'amount' => 0.0, 'label' => '', 'type' => '', 'error' => 'coupon_min_order'];
    }

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
        return ['ok' => false, 'code' => $code, 'percent' => 0.0, 'amount' => 0.0, 'label' => '', 'type' => $type, 'error' => 'coupon_unsupported_type'];
    }

    return [
        'ok' => true,
        'code' => (string)$row['code'],
        'percent' => $percent,
        'amount' => $amount,
        'label' => $label,
        'type' => $type,
        'error' => '',
    ];
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
<?php
declare(strict_types=1);

/**
 * Cupons de desconto reais, validados no servidor contra a tabela `coupons`
 * (ja existia no banco, usada por minha-conta/cupons.php -- mas estava
 * vazia e a query de la usava nomes de coluna errados, entao a pagina
 * "Meus Cupons" nunca mostrava nada e nenhum cupom podia ser resgatado de
 * verdade em lugar nenhum do site). `discount_type` aceita: percent, fixed,
 * shipping (frete gratis).
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
            'SELECT code, description, discount_type, discount_value
             FROM coupons
             WHERE code = :code AND is_active = 1
               AND (starts_at IS NULL OR starts_at <= NOW())
               AND (ends_at IS NULL OR ends_at >= NOW())
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
        // Desconto de frete e resolvido no checkout (nao aqui, que so trata
        // desconto sobre o valor dos itens) -- devolve ok com amount=0 pra
        // sinalizar "cupom valido, tipo frete" sem quebrar o fluxo atual.
        $amount = 0.0;
    } else {
        return ['ok' => false, 'code' => $code, 'percent' => 0.0, 'amount' => 0.0, 'label' => '', 'type' => $type, 'error' => 'coupon_unsupported_type'];
    }

    return ['ok' => true, 'code' => (string)$row['code'], 'percent' => $percent, 'amount' => $amount, 'label' => $label, 'type' => $type, 'error' => ''];
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

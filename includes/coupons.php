<?php
declare(strict_types=1);

/**
 * Cupons de desconto reais, validados no servidor. Antes disso, o popup de
 * carrinho abandonado (includes/navbar.php) e o assistente Liz mostravam um
 * cupom "VOLTEI5"/"5% de desconto via PIX" que nunca era checado em lugar
 * nenhum -- o cliente digitava e nada acontecia. Registro fica aqui, unico
 * lugar que precisa mudar se surgir um cupom novo.
 */

const SVCP_COUPONS = [
    'VOLTEI5' => ['percent' => 5.0, 'label' => 'Cupom carrinho abandonado (5%)'],
];

/**
 * @return array{ok: bool, code: string, percent: float, amount: float, label: string, error: string}
 */
function svcp_validate(string $rawCode, float $itemsSubtotal): array
{
    $code = strtoupper(trim($rawCode));
    if ($code === '') {
        return ['ok' => false, 'code' => '', 'percent' => 0.0, 'amount' => 0.0, 'label' => '', 'error' => 'coupon_empty'];
    }

    $entry = SVCP_COUPONS[$code] ?? null;
    if ($entry === null) {
        return ['ok' => false, 'code' => $code, 'percent' => 0.0, 'amount' => 0.0, 'label' => '', 'error' => 'coupon_invalid'];
    }

    if ($itemsSubtotal <= 0) {
        return ['ok' => false, 'code' => $code, 'percent' => 0.0, 'amount' => 0.0, 'label' => '', 'error' => 'coupon_empty_cart'];
    }

    $percent = (float)$entry['percent'];
    $amount = round($itemsSubtotal * $percent / 100, 2);

    return ['ok' => true, 'code' => $code, 'percent' => $percent, 'amount' => $amount, 'label' => (string)$entry['label'], 'error' => ''];
}

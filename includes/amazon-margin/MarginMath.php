<?php
declare(strict_types=1);

/**
 * ShopVivaliz Amazon margin accounting.
 *
 * Money is handled as integer BRL cents. Amazon Finances Shipment item's
 * totalAmount already includes its own commissions, FBA charges, shipping
 * principal and shipping rebates. DBA postage is a SEPARATE ServiceFee and
 * must be joined by ORDER_ID. Do not subtract either charge twice.
 */
final class SvAmazonMarginMath
{
    public static function cents(mixed $value): ?int
    {
        if ($value === null || $value === '') return null;
        if (!is_numeric($value) || !is_finite((float)$value)) return null;
        return (int) round(((float) $value) * 100, 0, PHP_ROUND_HALF_UP);
    }

    /**
     * @param array{sku:string,price:mixed,cost:mixed,quantity:int,shipment_total:mixed,program:string,
     *               postage_total?:mixed,postage_confirmed?:bool,tax_bps?:int} $line
     * @return array<string,mixed>
     */
    public static function calculate(array $line): array
    {
        $sku = trim((string)($line['sku'] ?? ''));
        $program = (string)($line['program'] ?? '');
        $quantity = (int)($line['quantity'] ?? 0);
        $price = self::cents($line['price'] ?? null);
        $cost = self::cents($line['cost'] ?? null);
        $shipment = self::cents($line['shipment_total'] ?? null);
        $taxBps = (int)($line['tax_bps'] ?? 1400);
        if ($sku === '' || !in_array($program, ['FBA_ONSITE', 'DBA'], true)
            || $quantity < 1 || $quantity > 100000 || $price === null || $price <= 0
            || $cost === null || $cost <= 0 || $shipment === null
            || $taxBps < 0 || $taxBps > 10000) {
            return ['status' => 'unverified', 'reason' => 'missing_or_invalid_financial_evidence', 'sku' => $sku];
        }

        if ($program === 'DBA' && !($line['postage_confirmed'] ?? false)) {
            return ['status' => 'pending_postage', 'reason' => 'mfn_postage_service_fee_not_reconciled', 'sku' => $sku];
        }
        $postage = $program === 'DBA' ? self::cents($line['postage_total'] ?? null) : 0;
        if ($postage === null || $postage > 0) {
            return ['status' => 'unverified', 'reason' => 'invalid_postage_charge', 'sku' => $sku];
        }

        $gross = $price * $quantity;
        $tax = (int)round($gross * $taxBps / 10000, 0, PHP_ROUND_HALF_UP);
        $cogs = $cost * $quantity;
        $profit = $shipment + $postage - $cogs - $tax;
        $marginBps = (int)round($profit * 10000 / $gross, 0, PHP_ROUND_HALF_UP);

        return [
            'status' => 'confirmed',
            'sku' => $sku,
            'program' => $program,
            'quantity' => $quantity,
            'gross_cents' => $gross,
            'shipment_receipt_cents' => $shipment,
            'dba_postage_cents' => $postage,
            'cost_cents' => $cogs,
            'tax_cents' => $tax,
            'profit_cents' => $profit,
            'margin_bps' => $marginBps,
            'below_10_pct' => $marginBps < 1000,
            'profit_per_unit_cents' => (int)round($profit / $quantity, 0, PHP_ROUND_HALF_UP),
        ];
    }

    public static function brl(int $cents): string
    {
        return number_format($cents / 100, 2, ',', '.');
    }
}

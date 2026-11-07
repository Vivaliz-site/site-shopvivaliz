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
     * Brazil Finances v2024 Shipment item: transactional gross BEFORE Amazon
     * fees is ProductCharges + Shipping + PromoRebates (top level only).
     * ProductCharges already contains nested principal components; do NOT
     * add children again. Shipping promotions commonly cancel customer
     * freight, while DBA can contain genuine buyer-paid shipping.
     *
     * Validate BRL, known breakdown types and actual item total so a new
     * Amazon fee category cannot silently corrupt a certified margin.
     * @return int|null Financially verified gross in BRL cents.
     */
    public static function transactionGrossCents(array $item): ?int
    {
        $lines=$item['breakdowns']??null;
        $total=$item['totalAmount']??null;
        if (!is_array($lines) || $lines===[] || !is_array($total)
            || ($total['currencyCode']??'')!=='BRL') return null;
        $net=self::cents($total['currencyAmount']??null);
        if ($net===null) return null;
        $totals=['ProductCharges'=>0,'Shipping'=>0,'PromoRebates'=>0,'AmazonFees'=>0];
        $hasProductCharge=false;
        foreach ($lines as $b) {
            if (!is_array($b)) return null;
            $kind=(string)($b['breakdownType']??'');
            $money=$b['breakdownAmount']??null;
            if (!array_key_exists($kind,$totals) || !is_array($money)
                || ($money['currencyCode']??'')!=='BRL') return null;
            $cents=self::cents($money['currencyAmount']??null);
            if ($cents===null) return null;
            $totals[$kind]+=$cents;
            if ($kind==='ProductCharges') $hasProductCharge=true;
        }
        $gross=$totals['ProductCharges']+$totals['Shipping']+$totals['PromoRebates'];
        if (!$hasProductCharge || $totals['ProductCharges']<=0
            || $totals['Shipping']<0 || $totals['PromoRebates']>0
            || $totals['AmazonFees']>0 || $gross<=0
            || abs($gross+$totals['AmazonFees']-$net)>1) return null;
        return $gross;
    }

    /**
     * @param array{sku:string,price:mixed,cost:mixed,quantity:int,shipment_total:mixed,program:string,
     *               postage_total?:mixed,postage_confirmed?:bool,tax_bps?:int,sale_gross_cents?:?int} $line
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
        $hasSaleGross=array_key_exists('sale_gross_cents',$line);
        $saleGross=$hasSaleGross ? $line['sale_gross_cents'] : null;
        if ($sku === '' || !in_array($program, ['FBA_ONSITE', 'DBA'], true)
            || $quantity < 1 || $quantity > 100000
            || (!$hasSaleGross && ($price === null || $price <= 0))
            || $cost === null || $cost <= 0 || $shipment === null
            || $taxBps < 0 || $taxBps > 10000
            || ($hasSaleGross && (!is_int($saleGross) || $saleGross<=0))) {
            return ['status' => 'unverified', 'reason' => 'missing_or_invalid_financial_evidence', 'sku' => $sku];
        }

        if ($program === 'DBA' && !($line['postage_confirmed'] ?? false)) {
            return ['status' => 'pending_postage', 'reason' => 'mfn_postage_service_fee_not_reconciled', 'sku' => $sku];
        }
        $postage = $program === 'DBA' ? self::cents($line['postage_total'] ?? null) : 0;
        if ($postage === null || $postage > 0) {
            return ['status' => 'unverified', 'reason' => 'invalid_postage_charge', 'sku' => $sku];
        }

        // The listing may now be inactive and have no effective current price.
        // Certified historical gross comes exclusively from Amazon Finances.
        // Without a verified sale gross, the legacy calculation still requires price.
        $gross = $hasSaleGross ? $saleGross : $price * $quantity;
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

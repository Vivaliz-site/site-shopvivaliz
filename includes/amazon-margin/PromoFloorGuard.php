<?php
declare(strict_types=1);

/** Read-only financial preflight for Amazon coupons, scoped to every buyable Seller SKU on one ASIN. */
final class SvAmazonPromoFloorGuard
{
    public const MIN_MARGIN_BPS = 1000;

    private static function discount(int $priceCents, int $bps): int
    {
        return intdiv($priceCents * $bps + 5000, 10000);
    }

    private static function integer(array $a, string $key): ?int
    {
        return array_key_exists($key, $a) && is_int($a[$key]) && $a[$key] >= 0 ? $a[$key] : null;
    }

    public static function evaluate(array $plan): array
    {
        $hold = static fn(string $why): array => ['status' => 'HOLD_EVIDENCE', 'reason' => $why];
        $asin = trim((string)($plan['asin'] ?? ''));
        $couponBps = self::integer($plan, 'coupon_bps');
        $extraBps = self::integer($plan, 'stacking_discount_bps');
        $couponFee = self::integer($plan, 'coupon_redemption_fee_cents');
        $adsCpa = self::integer($plan, 'ads_cpa_cents');
        if ($asin === '' || $couponBps === null || $couponBps < 500 || $couponBps > 5000
            || $extraBps === null || $extraBps > 10000
            || $couponFee === null || $adsCpa === null
            || ($plan['stacking_verified'] ?? false) !== true
            || ($plan['ads_attribution_verified'] ?? false) !== true) {
            return $hold('PROMOTION_FEE_STACKING_OR_ADS_UNVERIFIED');
        }
        $offers = $plan['offers'] ?? null;
        $expected = $plan['all_buyable_seller_skus'] ?? null;
        if (!is_array($offers) || !is_array($expected) || count($offers) === 0 || count($expected) === 0) {
            return $hold('ASIN_OFFERS_SCOPE_UNVERIFIED');
        }
        $seen = []; $results = []; $failed = false;
        foreach ($offers as $offer) {
            if (!is_array($offer) || ($offer['asin'] ?? '') !== $asin) return $hold('MIXED_OR_MISSING_ASIN');
            $sku = trim((string)($offer['seller_sku'] ?? ''));
            if ($sku === '' || isset($seen[$sku])) return $hold('DUPLICATE_OR_MISSING_SELLER_SKU');
            $seen[$sku] = true;
            if (($offer['buyable'] ?? false) !== true || ($offer['identity_verified'] ?? false) !== true
                || ($offer['kit_cost_verified'] ?? false) !== true || ($offer['logistics_verified'] ?? false) !== true
                || ($offer['tax_verified'] ?? false) !== true) {
                return $hold('UNVERIFIED_IDENTITY_KIT_PROGRAM_OR_TAX:' . $sku);
            }
            $keys = ['price_cents','live_price_cents','full_kit_cost_cents',
                'commission_current_cents','commission_promo_cents','logistics_current_cents',
                'logistics_promo_cents','tax_guard_cents','other_costs_cents','fee_quote_price_cents'];
            $values = [];
            foreach ($keys as $key) {
                $val = self::integer($offer, $key);
                if ($val === null) return $hold('MISSING_MONETARY_EVIDENCE:' . $sku . ':' . $key);
                $values[$key] = $val;
            }
            $base = $values['price_cents'];
            if ($base <= 0 || $base !== $values['live_price_cents']) {
                return $hold('LIVE_PRICE_MISMATCH:' . $sku);
            }
            $afterCoupon = $base - self::discount($base, $couponBps);
            $net = $afterCoupon - self::discount($afterCoupon, $extraBps);
            if ($net <= 0 || $net !== $values['fee_quote_price_cents']) {
                return $hold('DISCOUNTED_FEE_QUOTE_PRICE_MISMATCH:' . $sku);
            }
            $commission = max($values['commission_current_cents'], $values['commission_promo_cents']);
            $logistics = max($values['logistics_current_cents'], $values['logistics_promo_cents']);
            $tax = max($values['tax_guard_cents'], self::discount($base, 1400));
            $profit = $net - $values['full_kit_cost_cents'] - $commission - $logistics - $tax
                - $values['other_costs_cents'] - $couponFee - $adsCpa;
            $marginBps = intdiv($profit * 10000, $net);
            $headroom = $profit - self::discount($net, self::MIN_MARGIN_BPS);
            if ($profit * 10000 < self::MIN_MARGIN_BPS * $net) $failed = true;
            $results[$sku] = ['net_price_cents' => $net, 'net_profit_cents' => $profit,
                'margin_bps' => $marginBps, 'extra_headroom_cents' => max(0, $headroom)];
        }
        $actual = array_keys($seen);
        sort($actual, SORT_STRING); sort($expected, SORT_STRING);
        if ($actual !== $expected) return $hold('INCOMPLETE_ASIN_BUYABLE_SELLER_SKU_COVERAGE');
        return ['status' => $failed ? 'BLOCK_BELOW_10_PERCENT' : 'PASS_FINANCIAL_ONLY',
            'minimum_margin_bps' => self::MIN_MARGIN_BPS, 'offers' => $results,
            'reason' => $failed ? 'AT_LEAST_ONE_SELLER_SKU_BELOW_10_PERCENT'
                : 'ELIGIBILITY_STOCK_AND_COUPON_UI_STILL_REQUIRED'];
    }
}

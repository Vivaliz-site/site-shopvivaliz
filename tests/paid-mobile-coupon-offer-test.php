<?php
declare(strict_types=1);

function pmcp_assert(bool $ok, string $message): void
{
    if (!$ok) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$root = dirname(__DIR__);
require_once $root . '/includes/active-coupons.php';

$percent = sv_active_coupon_preview_price(71.56, ['type' => 'percent', 'value' => 10]);
pmcp_assert($percent !== null && abs($percent - 64.40) < 0.001, '10% coupon preview must calculate R$ 64,40 from R$ 71,56');

$fixed = sv_active_coupon_preview_price(71.56, ['type' => 'fixed', 'value' => 10]);
pmcp_assert($fixed !== null && abs($fixed - 61.56) < 0.001, 'fixed coupon preview must subtract the fixed amount');

pmcp_assert(sv_active_coupon_preview_price(71.56, ['type' => 'shipping', 'value' => 10]) === null, 'unsupported shipping coupon must never invent a product price');
pmcp_assert(sv_active_coupon_preview_price(0, ['type' => 'percent', 'value' => 10]) === null, 'zero product price must not produce a preview');

$product = (string)file_get_contents($root . '/produto.php');
pmcp_assert(str_contains($product, 'sv_active_coupon_preview_price'), 'product page must derive the preview from the canonical active coupon helper');
pmcp_assert(str_contains($product, 'sv-paid-mobile-offer-effective-price'), 'mobile paid offer must show the effective coupon price when safe');
pmcp_assert(str_contains($product, 'Preço com cupom'), 'coupon price must be explicitly labeled as conditional, not as the base price');

fwrite(STDOUT, "PASS: paid mobile coupon price contract.\n");

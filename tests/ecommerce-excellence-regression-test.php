<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/buy-together.php';
require_once dirname(__DIR__) . '/includes/checkout-output-hardening.php';

function sv_test_assert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$singleSku = svbt_validate_offer(null, [
    ['sku' => 'A', 'price' => 100.00, 'quantity' => 3],
]);
sv_test_assert($singleSku['active'] === false, 'one distinct SKU must not activate 3% promotion');
sv_test_assert((float)$singleSku['amount'] === 0.0, 'one distinct SKU must have zero promotion amount');

$mixedCart = svbt_validate_offer(null, [
    ['sku' => 'A', 'price' => 100.00, 'quantity' => 2],
    ['sku' => 'B', 'price' => 50.00, 'quantity' => 3],
]);
sv_test_assert($mixedCart['active'] === true, 'two distinct SKUs must activate 3% promotion');
sv_test_assert(abs((float)$mixedCart['eligible_subtotal'] - 350.00) < 0.001, 'all quantities must be included in eligible subtotal');
sv_test_assert(abs((float)$mixedCart['amount'] - 10.50) < 0.001, '3% must apply to every eligible unit in mixed cart');
sv_test_assert(count($mixedCart['skus']) === 2, 'promotion must report both distinct SKUs');

$roundingCart = svbt_validate_offer(null, [
    ['sku' => 'ROUND-A', 'price' => 19.99, 'quantity' => 1],
    ['sku' => 'ROUND-B', 'price' => 9.99, 'quantity' => 1],
]);
sv_test_assert($roundingCart['active'] === true, 'rounding fixture must be eligible');
sv_test_assert(abs((float)$roundingCart['amount'] - 0.90) < 0.001, 'promotion rounding must remain stable per line');

$fixture = <<<'HTML'
<html><body>
Garanta o seu estoque! Os produtos no seu carrinho estão reservados por <strong id="checkout-timer-display">15:00</strong> minutos.
<script>
fetch('/api/orders/validate-coupon.php', {
  body: JSON.stringify({ coupon_code: code, items: getCart() })
});
</script>
</body></html>
HTML;

$filtered = svcoh_filter($fixture);
sv_test_assert(!str_contains($filtered, 'estão reservados por'), 'checkout must not claim a fictitious stock reservation');
sv_test_assert(str_contains($filtered, 'revalidados no servidor'), 'checkout must explain server-side revalidation');
sv_test_assert(str_contains($filtered, 'customer_email:'), 'coupon preview must include checkout customer email');
sv_test_assert(str_contains($filtered, '[name="customer_email"]'), 'coupon preview must read the email field');

fwrite(STDOUT, "ecommerce_excellence_regression=ok\n");

<?php
declare(strict_types=1);

$checkout = (string) file_get_contents(dirname(__DIR__) . '/checkout.php');
$failures = [];
foreach (['checkout-status', 'coupon-status', 'checkout-shipping-status'] as $id) {
    $pattern = '/<div\\b(?=[^>]*\\bid="' . preg_quote($id, '/') . '")[^>]*>/i';
    if (!preg_match($pattern, $checkout, $match)) {
        $failures[] = "$id: missing status element";
        continue;
    }
    $tag = $match[0];
    foreach (['role="status"', 'aria-live="polite"', 'aria-atomic="true"'] as $attribute) {
        if (!str_contains($tag, $attribute)) {
            $failures[] = "$id: missing $attribute";
        }
    }
}
if ($failures) {
    fwrite(STDERR, implode(PHP_EOL, $failures) . PHP_EOL);
    exit(1);
}
echo "checkout-async-status-a11y: ok\n";

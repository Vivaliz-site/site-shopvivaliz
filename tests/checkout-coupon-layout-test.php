<?php
declare(strict_types=1);
$html = file_get_contents(dirname(__DIR__) . '/checkout.php');
if (!is_string($html)) throw new RuntimeException('checkout source missing');
$checks = 0;
function requireRule(bool $ok, string $message): void {
    global $checks;
    if (!$ok) { fwrite(STDERR, 'FAIL: ' . $message . PHP_EOL); exit(1); }
    ++$checks;
}
requireRule(preg_match('/class="sv-coupon-box"[^>]*style="[^"]*flex-wrap:\s*wrap/', $html) === 1,
    'coupon controls must wrap instead of overflowing the summary');
requireRule(preg_match('/id="coupon-input"[^>]*style="[^"]*flex:\s*1\s+1\s+100%/', $html) === 1,
    'coupon entry must have its own full-width row');
requireRule(preg_match('/id="coupon-input"[^>]*style="[^"]*min-width:\s*0/', $html) === 1,
    'coupon entry must be allowed to shrink inside narrow cards');
foreach (['coupon-apply-btn', 'coupon-remove-btn'] as $id) {
    requireRule(preg_match('/id="' . $id . '"[^>]*style="[^"]*min-height:\s*44px/', $html) === 1,
        $id . ' must keep a usable touch target');
}
requireRule(preg_match('/id="coupon-remove-btn"[^>]*\bhidden\b/', $html) === 1,
    'remove remains hidden when there is no applied coupon');
echo 'CHECKOUT_COUPON_LAYOUT_PASS checks=' . $checks . PHP_EOL;

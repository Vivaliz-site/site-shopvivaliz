<?php
declare(strict_types=1);

// Regression: out-of-stock products do not render a mobile sticky "Comprar"
// control pointing at a missing #buy-now button.
$source = file_get_contents(__DIR__ . '/../produto.php');
if (!is_string($source) || $source === '') {
    fwrite(STDERR, "cannot_read_product_template\n");
    exit(1);
}
$stickyAnchor = '<div class="sticky-buy-wrapper">';
$pos = strpos($source, $stickyAnchor);
if ($pos === false || strpos($source, $stickyAnchor, $pos + 1) !== false) {
    fwrite(STDERR, "mobile_sticky_buy_missing_or_duplicate\n");
    exit(1);
}
$guard = '<?php if (!$notFound && $priceRaw > 0 && $stockRaw > 0): ?>';
$prefix = substr($source, max(0, $pos - 190), min(190, $pos));
if (!str_ends_with(rtrim($prefix), $guard)) {
    fwrite(STDERR, "mobile_sticky_buy_not_guarded_by_stock_and_price\n");
    exit(1);
}
$widget = substr($source, $pos, 800);
if (!str_contains($widget, 'document.getElementById(\'buy-now\').click()')
    || !str_contains($widget, '</div>' . "\n    <?php endif; ?>")) {
    fwrite(STDERR, "mobile_sticky_buy_conditional_not_closed\n");
    exit(1);
}
if (!str_contains($source, '<?php if ($priceRaw > 0 && $stockRaw > 0): ?>' . "\n                <div class=\"product-buy-group\">")
    || !str_contains($source, 'id="stock-alert-form"')) {
    fwrite(STDERR, "main_buy_and_soldout_contract_changed\n");
    exit(1);
}
echo "product_mobile_sticky_out_of_stock=GREEN\n";

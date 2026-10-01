<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$daemon = (string) file_get_contents($root . '/daemon-sync-products.py');
$shipping = (string) file_get_contents($root . '/api/melhorenvio/shipping-check-v2.php');
$merchant = (string) file_get_contents($root . '/google-merchant-feed.php');

$errors = [];
if (!str_contains($daemon, '"pesoBruto": float(dimensions.get("pesoBruto")')) {
    $errors[] = 'catalog_missing_gross_weight';
}
if (!str_contains($shipping, "['gross_weight','weight','peso']")) {
    $errors[] = 'quote_not_using_gross_weight_first';
}
$live = strpos($merchant, "$product['gross_weight']");
$fallback = strpos($merchant, "$fallbackDimensions['gross_weight']");
if ($live === false || $fallback === false || $live >= $fallback) {
    $errors[] = 'merchant_live_package_data_must_precede_fallback';
}

if ($errors !== []) {
    fwrite(STDERR, "SHIPPING_SOURCE_INTEGRITY_FAILED\n" . implode("\n", $errors) . "\n");
    exit(1);
}
echo "SHIPPING_SOURCE_INTEGRITY_OK\n";

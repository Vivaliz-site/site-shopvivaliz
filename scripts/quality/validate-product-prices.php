<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/catalog-runtime.php';
$rows = svcr_products();
$valid = 0; $invalid = 0; $stockWithoutPrice = 0;
foreach ($rows as $row) {
    if (!is_array($row)) continue;
    $price = (float)($row['price'] ?? 0); $stock = (int)($row['stock'] ?? 0);
    if ($price > 0) $valid++; else { $invalid++; if ($stock > 0) $stockWithoutPrice++; }
}
$required = ['api/catalog/price-health.php','api/catalog/products-with-valid-price.php','api/catalog/price-by-product.php','js/catalog-price-integrity-v72.js','js/product-price-integrity-v73.js','css/price-integrity-v73.css'];
$errors = [];
foreach ($required as $file) if (!is_file(__DIR__ . '/../../' . $file)) $errors[] = "missing: $file";
if ($valid === 0) $errors[] = 'no products with valid price';
if ($errors) { fwrite(STDERR, implode(PHP_EOL, $errors) . PHP_EOL); exit(1); }
echo "Product price validation passed. valid=$valid invalid=$invalid stock_without_price=$stockWithoutPrice\n";

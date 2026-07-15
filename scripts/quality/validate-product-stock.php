<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/catalog-runtime.php';
$rows = svcr_products();
$available = 0; $out = 0; $negative = 0;
foreach ($rows as $row) {
    if (!is_array($row)) continue;
    $stock = (int)($row['stock'] ?? 0);
    if ($stock > 0) $available++; else $out++;
    if ($stock < 0) $negative++;
}
$required = ['api/catalog/stock-health.php','api/catalog/products-in-stock.php','api/catalog/stock-by-product.php','js/catalog-stock-integrity-v82.js','js/product-stock-integrity-v83.js','css/stock-integrity-v83.css'];
$errors = [];
foreach ($required as $file) if (!is_file(__DIR__ . '/../../' . $file)) $errors[] = "missing: $file";
if ($negative > 0) $errors[] = "negative stock records: $negative";
if ($errors) { fwrite(STDERR, implode(PHP_EOL, $errors) . PHP_EOL); exit(1); }
if ($available === 0) echo "⚠️  Warning: no products with available stock (may be normal if catalog is low)\n";
echo "Product stock validation passed. available=$available out=$out\n";

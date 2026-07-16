<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/catalog-runtime.php';
$rows = svcr_products();
$valid = 0;
$invalid = 0;
foreach (is_array($rows) ? $rows : [] as $row) {
    if (!is_array($row)) continue;
    $image = trim((string)($row['image_url'] ?? ''));
    $lower = strtolower($image);
    if ($image === '' || str_contains($lower, 'placeholder') || str_contains($lower, 'logo-vivaliz')) $invalid++;
    else $valid++;
}

$required = [
    'api/catalog/valid-image-products.php',
    'api/catalog/image-by-product.php',
    'js/catalog-image-integrity-v62.js',
    'js/product-image-integrity-v63.js',
    'css/product-image-integrity-v63.css',
];
$errors = [];
foreach ($required as $file) if (!is_file(__DIR__ . '/../../' . $file)) $errors[] = "missing: {$file}";
if ($valid === 0) $errors[] = 'no valid product images found';
if ($errors) { fwrite(STDERR, implode(PHP_EOL, $errors) . PHP_EOL); exit(1); }

echo "Product image validation passed. valid={$valid} invalid={$invalid}\n";

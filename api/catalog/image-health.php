<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

require_once dirname(__DIR__, 2) . '/includes/catalog-runtime.php';
$products = svcr_products();
$summary = [
    'products_total' => 0,
    'products_with_real_image' => 0,
    'products_without_image' => 0,
    'products_with_placeholder' => 0,
    'categories_with_real_image' => 0,
];
$categories = [];

foreach (is_array($products) ? $products : [] as $row) {
    if (!is_array($row)) continue;
    $summary['products_total']++;
    $image = trim((string)($row['image_url'] ?? ''));
    $category = trim((string)($row['category'] ?? ''));
    $lower = strtolower($image);

    if ($image === '') {
        $summary['products_without_image']++;
        continue;
    }
    if (str_contains($lower, 'placeholder') || str_contains($lower, 'logo-vivaliz')) {
        $summary['products_with_placeholder']++;
        continue;
    }

    $summary['products_with_real_image']++;
    if ($category !== '') $categories[$category] = true;
}

$summary['categories_with_real_image'] = count($categories);
$summary['coverage_percent'] = $summary['products_total'] > 0
    ? round(($summary['products_with_real_image'] / $summary['products_total']) * 100, 2)
    : 0.0;

echo json_encode(['ok' => true, 'image_health' => $summary], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

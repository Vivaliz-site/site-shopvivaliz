<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: public, max-age=300');
header('X-Content-Type-Options: nosniff');
require_once dirname(__DIR__, 2) . '/includes/catalog-runtime.php';

function svvip_valid_image(string $image): bool {
    $image = trim($image);
    if ($image === '') return false;
    $lower = strtolower($image);
    if (str_contains($lower, 'placeholder') || str_contains($lower, 'logo-vivaliz')) return false;
    return str_starts_with($image, '/') || str_starts_with($image, 'https://') || str_starts_with($image, 'http://');
}

$rows = svcr_products();
$products = [];

foreach (is_array($rows) ? $rows : [] as $row) {
    if (!is_array($row)) continue;
    $image = trim((string)($row['image_url'] ?? ''));
    if (!svvip_valid_image($image)) continue;
    $products[] = [
        'sku' => trim((string)($row['sku'] ?? $row['id'] ?? '')),
        'olist_product_id' => trim((string)($row['olist_product_id'] ?? $row['id'] ?? '')),
        'slug' => trim((string)($row['slug'] ?? '')),
        'name' => trim((string)($row['name'] ?? '')),
        'category' => trim((string)($row['category'] ?? '')),
        'image_url' => $image,
        'price' => (float)($row['price'] ?? 0),
        'stock' => (int)($row['stock'] ?? 0),
    ];
}

echo json_encode(['ok' => true, 'count' => count($products), 'products' => $products], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: public, max-age=300');
header('X-Content-Type-Options: nosniff');

function svci_normalize(string $value): string {
    $value = trim(mb_strtolower($value, 'UTF-8'));
    $converted = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
    return preg_replace('/\s+/', ' ', is_string($converted) ? $converted : $value) ?: '';
}

function svci_valid_image(string $value): bool {
    $value = trim($value);
    if ($value === '') return false;
    $lower = strtolower($value);
    if (str_contains($lower, 'placeholder') || str_contains($lower, 'logo-vivaliz')) return false;
    return str_starts_with($value, '/') || str_starts_with($value, 'http://') || str_starts_with($value, 'https://');
}

$path = __DIR__ . '/fallback-products.json';
$decoded = is_file($path) ? json_decode((string)file_get_contents($path), true) : [];
$categories = [];

foreach (is_array($decoded) ? $decoded : [] as $row) {
    if (!is_array($row)) continue;
    $category = trim((string)($row['category'] ?? ''));
    $image = trim((string)($row['image_url'] ?? ''));
    if ($category === '' || !svci_valid_image($image)) continue;

    $key = svci_normalize($category);
    $score = ((int)($row['stock'] ?? 0) > 0 ? 4 : 0)
        + ((float)($row['price'] ?? 0) > 0 ? 2 : 0)
        + (trim((string)($row['slug'] ?? '')) !== '' ? 1 : 0);

    if (!isset($categories[$key]) || $score > $categories[$key]['score']) {
        $categories[$key] = [
            'category' => $category,
            'image_url' => $image,
            'sku' => (string)($row['sku'] ?? $row['id'] ?? ''),
            'product_name' => (string)($row['name'] ?? ''),
            'score' => $score,
        ];
    }
}

foreach ($categories as &$category) unset($category['score']);
unset($category);

ksort($categories);
echo json_encode(['ok' => true, 'categories' => array_values($categories)], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

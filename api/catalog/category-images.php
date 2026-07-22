<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: public, max-age=300');
header('X-Content-Type-Options: nosniff');
require_once dirname(__DIR__, 2) . '/includes/catalog-runtime.php';

function svci_normalize(string $value): string {
    return trim(function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value));
}

function svci_valid_image(string $value): bool {
    $value = trim($value);
    if ($value === '') return false;
    $lower = strtolower($value);
    if (str_contains($lower, 'placeholder') || str_contains($lower, 'logo-vivaliz')) return false;
    if (str_starts_with($value, 'http://') || str_starts_with($value, 'https://')) return true;
    if (!str_starts_with($value, '/')) return false;
    $path = dirname(__DIR__, 2) . parse_url($value, PHP_URL_PATH);
    return is_file($path);
}

$decoded = svcr_products();
$categories = [];

$generic_images = [
    'armários e organização' => 'https://images.unsplash.com/photo-1558618666-fcd25c85cd64?w=320&q=80',
    'banheiro' => 'https://images.unsplash.com/photo-1584622650111-993a426fbf0a?w=320&q=80',
    'cadeados e segurança' => 'https://images.unsplash.com/photo-1558025137-0b407a944810?w=320&q=80',
    'caixas de ferramentas' => 'https://images.unsplash.com/photo-1530124566582-a618bc2615dc?w=320&q=80',
    'elétrico e automotivo' => 'https://images.unsplash.com/photo-1492144534655-ae79c964c9d7?w=320&q=80',
    'ferramentas' => 'https://images.unsplash.com/photo-1530124566582-a618bc2615dc?w=320&q=80',
    'fixação e ferragem' => 'https://images.unsplash.com/photo-1504148455328-c376907d081c?w=320&q=80',
    'floreiras e jardim' => 'https://images.unsplash.com/photo-1416879595882-3373a0480b5b?w=320&q=80',
    'pet' => 'https://images.unsplash.com/photo-1450778869180-41d0601e046e?w=320&q=80',
    'rodízios' => 'https://images.unsplash.com/photo-1581235720704-06d3acfcb36f?w=320&q=80',
    'utilidades' => 'https://images.unsplash.com/photo-1583847268964-b28dc8f51f92?w=320&q=80',
];

foreach (is_array($decoded) ? $decoded : [] as $row) {
    if (!is_array($row)) continue;
    $category = trim((string)($row['category'] ?? ''));
    if ($category === '') continue;
    if ((int)($row['stock'] ?? 0) <= 0 || (float)($row['price'] ?? 0) <= 0) continue;

    $key = svci_normalize($category);
    $generic_image = $generic_images[$key] ?? 'https://images.unsplash.com/photo-1583847268964-b28dc8f51f92?w=320&q=80';
    $realImage = trim((string)($row['image_url'] ?? ''));
    $image = svci_valid_image($realImage) ? $realImage : $generic_image;
    $score = ((int)($row['stock'] ?? 0) > 0 ? 4 : 0)
        + ((float)($row['price'] ?? 0) > 0 ? 2 : 0)
        + (svci_valid_image($realImage) ? 3 : 0)
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

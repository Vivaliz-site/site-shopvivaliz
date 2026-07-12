<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: public, max-age=300');
header('X-Content-Type-Options: nosniff');

function svci_normalize(string $value): string {
    $value = trim(function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value));
    $converted = function_exists('iconv') ? @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value) : $value;
    return preg_replace('/\s+/', ' ', is_string($converted) && $converted !== '' ? $converted : $value) ?: '';
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

$generic_images = [
    'armarios e organizacao' => 'https://images.unsplash.com/photo-1595428774223-ef52624120d2?w=320&q=80',
    'banheiro' => 'https://images.unsplash.com/photo-1584622650111-993a426fbf0a?w=320&q=80',
    'cadeados e seguranca' => 'https://images.unsplash.com/photo-1558025137-0b407a944810?w=320&q=80',
    'caixas de ferramentas' => 'https://images.unsplash.com/photo-1530124566582-a618bc2615dc?w=320&q=80',
    'eletrico e automotivo' => 'https://images.unsplash.com/photo-1492144534655-ae79c964c9d7?w=320&q=80',
    'ferramentas' => 'https://images.unsplash.com/photo-1581166397057-235af2b3c6dd?w=320&q=80',
    'fixacao e ferragem' => 'https://images.unsplash.com/photo-1586864387967-d02ef85d93e8?w=320&q=80',
    'floreiras e jardim' => 'https://images.unsplash.com/photo-1416879598556-33b63b27b87c?w=320&q=80',
    'pet' => 'https://images.unsplash.com/photo-1450778869180-41d0601e046e?w=320&q=80',
    'rodizios' => 'https://images.unsplash.com/photo-1601597111158-2fceff292cdc?w=320&q=80',
    'utilidades' => 'https://images.unsplash.com/photo-1583847268964-b28dc8f51f92?w=320&q=80',
];

foreach (is_array($decoded) ? $decoded : [] as $row) {
    if (!is_array($row)) continue;
    $category = trim((string)($row['category'] ?? ''));
    if ($category === '') continue;

    $key = svci_normalize($category);
    $generic_image = $generic_images[$key] ?? 'https://images.unsplash.com/photo-1583847268964-b28dc8f51f92?w=320&q=80';
    $score = ((int)($row['stock'] ?? 0) > 0 ? 4 : 0)
        + ((float)($row['price'] ?? 0) > 0 ? 2 : 0)
        + (trim((string)($row['slug'] ?? '')) !== '' ? 1 : 0);

    if (!isset($categories[$key]) || $score > $categories[$key]['score']) {
        $categories[$key] = [
            'category' => $category,
            'image_url' => $generic_image,
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

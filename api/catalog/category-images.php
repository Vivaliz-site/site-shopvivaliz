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
    return str_starts_with($value, '/') || str_starts_with($value, 'http://') || str_starts_with($value, 'https://');
}

$decoded = svcr_products();
$categories = [];

$generic_images = [
    'armários e organização' => '/public/assets/category-images/cat-organizacao.jpg',
    'banheiro' => 'https://images.unsplash.com/photo-1584622650111-993a426fbf0a?w=320&q=80',
    'cadeados e segurança' => 'https://images.unsplash.com/photo-1558025137-0b407a944810?w=320&q=80',
    'caixas de ferramentas' => 'https://images.unsplash.com/photo-1530124566582-a618bc2615dc?w=320&q=80',
    'elétrico e automotivo' => 'https://images.unsplash.com/photo-1492144534655-ae79c964c9d7?w=320&q=80',
    'ferramentas' => '/public/assets/category-images/cat-ferramentas.jpg',
    'fixação e ferragem' => '/public/assets/category-images/cat-ferragens.jpg',
    'floreiras e jardim' => '/public/assets/category-images/cat-jardim.jpg',
    'pet' => 'https://images.unsplash.com/photo-1450778869180-41d0601e046e?w=320&q=80',
    'rodízios' => '/public/assets/category-images/cat-rodizios.jpg',
    'utilidades' => 'https://images.unsplash.com/photo-1583847268964-b28dc8f51f92?w=320&q=80',
];

foreach (is_array($decoded) ? $decoded : [] as $row) {
    if (!is_array($row)) continue;
    $category = trim((string)($row['category'] ?? ''));
    if ($category === '') continue;

    $key = svci_normalize($category);
    $productName = strtolower(trim((string)($row['name'] ?? '')));

    // Evitar descompasso (ex: gancho de rede na categoria banheiro)
    if ($key === 'banheiro' && (str_contains($productName, 'gancho de rede') || str_contains($productName, 'rede'))) {
        continue;
    }

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

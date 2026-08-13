<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: public, max-age=300');
header('X-Content-Type-Options: nosniff');
require_once dirname(__DIR__, 2) . '/includes/catalog-runtime.php';
require_once dirname(__DIR__, 2) . '/includes/catalog-image-enrich.php';

function svci_normalize(string $value): string
{
    return trim(function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value));
}

function svci_valid_image(string $value): bool
{
    $value = trim($value);
    if ($value === '') return false;
    $lower = strtolower($value);
    if (str_contains($lower, 'placeholder')
        || str_contains($lower, 'logo-vivaliz')
        || str_contains($lower, 'unsplash.com')
        || str_contains($lower, '/public/assets/category-images/')) {
        return false;
    }
    return str_starts_with($value, '/')
        || str_starts_with($value, 'http://')
        || str_starts_with($value, 'https://');
}

/** @return list<string> */
function svci_row_images(array $row): array
{
    $images = [];
    foreach (['image_url', 'image', 'imagem_principal_url', 'primary_image_url'] as $field) {
        $candidate = trim((string)($row[$field] ?? ''));
        if (svci_valid_image($candidate)) $images[] = $candidate;
    }
    foreach (['images', 'imagens', 'gallery', 'galeria'] as $field) {
        if (!is_array($row[$field] ?? null)) continue;
        foreach ($row[$field] as $candidate) {
            if (is_array($candidate)) {
                $candidate = $candidate['url'] ?? $candidate['image_url'] ?? $candidate['src'] ?? '';
            }
            $candidate = trim((string)$candidate);
            if (svci_valid_image($candidate)) $images[] = $candidate;
        }
    }
    return array_values(array_unique($images));
}

function svci_image_score(string $url): int
{
    $lower = strtolower($url);
    $score = 0;
    foreach (['ambient', 'lifestyle', 'context', 'uso', 'scene', 'cenario'] as $needle) {
        if (str_contains($lower, $needle)) $score += 12;
    }
    foreach (['thumb', 'thumbnail', 'small', 'mini', 'preview'] as $needle) {
        if (str_contains($lower, $needle)) $score -= 10;
    }
    return $score;
}

$rows = array_values(array_filter(svcr_products(), 'is_array'));
$rows = svcie_enrich_images($rows);
$categories = [];

$localFallbacks = [
    'armários e organização' => '/public/assets/category-images/cat-organizacao.jpg',
    'banheiro' => '/public/assets/category-images/cat-organizacao.jpg',
    'cadeados e segurança' => '/public/assets/category-images/cat-ferragens.jpg',
    'caixas de ferramentas' => '/public/assets/category-images/cat-ferramentas.jpg',
    'elétrico e automotivo' => '/public/assets/category-images/cat-ferramentas.jpg',
    'ferramentas' => '/public/assets/category-images/cat-ferramentas.jpg',
    'fixação e ferragem' => '/public/assets/category-images/cat-ferragens.jpg',
    'floreiras e jardim' => '/public/assets/category-images/cat-jardim.jpg',
    'pet' => '/public/assets/category-images/cat-jardim.jpg',
    'rodízios' => '/public/assets/category-images/cat-rodizios.jpg',
    'utilidades' => '/public/assets/category-images/cat-organizacao.jpg',
];

foreach ($rows as $row) {
    $category = trim((string)($row['category'] ?? ''));
    if ($category === '') continue;

    $key = svci_normalize($category);
    $productName = svci_normalize((string)($row['name'] ?? ''));

    // Evita um descompasso conhecido do ERP sem inventar outra categoria.
    if ($key === 'banheiro' && (str_contains($productName, 'gancho de rede') || str_contains($productName, 'rede'))) {
        continue;
    }

    $images = svci_row_images($row);
    usort($images, static fn(string $a, string $b): int => svci_image_score($b) <=> svci_image_score($a));
    $realImage = $images[0] ?? '';
    $fallback = $localFallbacks[$key] ?? '/public/assets/category-images/cat-organizacao.jpg';

    $score = ((int)($row['stock'] ?? 0) > 0 ? 8 : 0)
        + ((float)($row['price'] ?? 0) > 0 ? 4 : 0)
        + ($realImage !== '' ? 12 : 0)
        + ($realImage !== '' ? svci_image_score($realImage) : 0)
        + (trim((string)($row['slug'] ?? '')) !== '' ? 1 : 0);

    if (!isset($categories[$key]) || $score > $categories[$key]['score']) {
        $categories[$key] = [
            'category' => $category,
            'image_url' => $realImage !== '' ? $realImage : $fallback,
            'image_source' => $realImage !== '' ? 'catalog' : 'local-fallback',
            'sku' => (string)($row['sku'] ?? $row['id'] ?? ''),
            'product_name' => (string)($row['name'] ?? ''),
            'score' => $score,
        ];
    }
}

foreach ($categories as &$category) unset($category['score']);
unset($category);

ksort($categories);
echo json_encode(
    ['ok' => true, 'categories' => array_values($categories)],
    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
);

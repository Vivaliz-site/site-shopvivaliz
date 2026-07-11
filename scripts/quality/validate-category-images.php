<?php
declare(strict_types=1);

$path = __DIR__ . '/../../api/catalog/fallback-products.json';
$products = is_file($path) ? json_decode((string) file_get_contents($path), true) : [];
$errors = [];
$categories = [];

foreach (is_array($products) ? $products : [] as $row) {
    if (!is_array($row)) continue;
    $category = trim((string)($row['category'] ?? ''));
    $image = trim((string)($row['image_url'] ?? ''));
    if ($category === '') continue;
    $lower = strtolower($image);
    if ($image !== '' && !str_contains($lower, 'placeholder') && !str_contains($lower, 'logo-vivaliz')) {
        $categories[$category] = true;
    }
}

if ($categories === []) $errors[] = 'Nenhuma categoria possui imagem real válida.';
if (!is_file(__DIR__ . '/../../api/catalog/category-images.php')) $errors[] = 'Endpoint de imagens por categoria ausente.';
if (!is_file(__DIR__ . '/../../js/category-real-images-v52.js')) $errors[] = 'Script de imagens reais ausente.';

if ($errors) {
    fwrite(STDERR, implode(PHP_EOL, $errors) . PHP_EOL);
    exit(1);
}

echo 'Category image validation passed for ' . count($categories) . " categorias.\n";

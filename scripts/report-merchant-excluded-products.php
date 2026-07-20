<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/catalog-runtime.php';
require_once __DIR__ . '/../includes/product-seo.php';

$rows = [];
foreach (svcr_products() as $product) {
    if (!is_array($product)) {
        continue;
    }

    $id = trim((string)($product['sku'] ?? $product['olist_product_id'] ?? $product['id'] ?? ''));
    $slug = trim((string)($product['slug'] ?? ''));
    $name = svseo_human_name($product);
    $image = trim((string)($product['image_url'] ?? ''));
    $price = (float)($product['price'] ?? 0);
    $stock = (int)($product['stock'] ?? 0);
    $reasons = [];

    if ($id === '') {
        $reasons[] = 'id/sku ausente';
    }
    if ($slug === '') {
        $reasons[] = 'slug ausente';
    }
    if ($name === '') {
        $reasons[] = 'nome ausente';
    }
    if ($image === '') {
        $reasons[] = 'imagem ausente';
    }
    if ($price <= 0) {
        $reasons[] = 'preco invalido/ausente';
    }

    if ($reasons === []) {
        continue;
    }

    $rows[] = [
        'sku' => $id !== '' ? $id : '(sem SKU)',
        'nome' => $name !== '' ? $name : trim((string)($product['name'] ?? '(sem nome)')),
        'preco' => number_format($price, 2, ',', '.'),
        'estoque' => (string)$stock,
        'slug' => $slug !== '' ? $slug : '(sem slug)',
        'imagem' => $image !== '' ? $image : '(sem imagem)',
        'motivos' => implode('; ', $reasons),
    ];
}

echo json_encode([
    'total_excluidos' => count($rows),
    'rows' => $rows,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . PHP_EOL;

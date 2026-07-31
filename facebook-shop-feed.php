<?php
/**
 * Facebook/Instagram Shop Product Feed
 * JSON format for Commerce Manager
 * Auto-generated from catalog
 */

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: public, max-age=3600');

require_once __DIR__ . '/includes/catalog-runtime.php';

$products = array_slice(sv_home_catalog_source_rows(), 0, 1000);

$feed = [
    'id' => 'shopvivaliz_catalog_' . date('Y-m-d-H-i-s'),
    'name' => 'ShopVivaliz - Catálogo Completo',
    'description' => 'Produtos ShopVivaliz para Facebook e Instagram Shop',
    'currency' => 'BRL',
    'language' => 'pt_BR',
    'country' => 'BR',
    'created_date' => date('c'),
    'updated_date' => date('c'),
    'products' => []
];

foreach ($products as $product) {
    $sku = trim((string)($product['sku'] ?? ''));
    $name = trim((string)($product['name'] ?? ''));
    $image = trim((string)($product['image_url'] ?? ''));
    $price = (float)($product['price'] ?? 0);
    $category = trim((string)($product['category'] ?? 'Produtos'));
    $stock = (int)($product['stock'] ?? 0);
    $url = 'https://shopvivaliz.com.br' . (isset($product['slug']) ? '/produto/' . $product['slug'] : '/catalogo');

    if (empty($sku) || empty($name) || $price <= 0 || empty($image)) {
        continue;
    }

    $feed['products'][] = [
        'id' => $sku,
        'title' => $name,
        'description' => substr($product['description'] ?? 'Produto de qualidade ShopVivaliz', 0, 5000),
        'price' => $price,
        'currency' => 'BRL',
        'image_url' => $image,
        'url' => $url,
        'availability' => $stock > 0 ? 'in stock' : 'out of stock',
        'category' => $category,
        'brand' => 'ShopVivaliz',
        'condition' => 'new',
        'stock_quantity' => $stock,
        'rating' => (float)($product['rating'] ?? 4.8),
        'reviews_count' => (int)($product['reviews_count'] ?? 0),
        'sale_price' => $price > 0 ? round($price * 0.9, 2) : 0, // 10% discount example
        'shipping_weight' => 0.5, // kg
        'shipping_cost' => 0, // Free shipping on base
        'gtin' => $sku,
        'mpn' => $sku
    ];
}

// Return JSON feed
echo json_encode($feed, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
?>

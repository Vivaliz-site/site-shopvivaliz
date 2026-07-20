<?php
declare(strict_types=1);

header('Content-Type: application/xml; charset=UTF-8');
require_once __DIR__ . '/includes/catalog-runtime.php';
require_once __DIR__ . '/includes/product-seo.php';

$official = __DIR__ . '/config/official-site.php';
$officialData = is_file($official) ? (@include $official) : [];
$baseUrl = is_array($officialData) && trim((string)($officialData['base_url'] ?? '')) !== ''
    ? rtrim((string)$officialData['base_url'], '/')
    : 'https://shopvivaliz.com.br';

$products = svcr_products();

function gm_xml(string $value): string
{
    return htmlspecialchars($value, ENT_XML1 | ENT_COMPAT, 'UTF-8');
}

function gm_lower(string $value): string
{
    return function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
}

function gm_brand(array $product): string
{
    $name = gm_lower(trim((string)($product['name'] ?? '')));
    $tags = array_map(
        static fn ($tag): string => gm_lower(trim((string)$tag)),
        is_array($product['tags'] ?? null) ? $product['tags'] : []
    );

    foreach (['soprano', 'gedore', 'astra', 'fercar', 'papaiz', 'japi', 'aquatools'] as $brand) {
        if (str_contains($name, $brand) || in_array($brand, $tags, true)) {
            return ucfirst($brand);
        }
    }

    return 'Vivaliz';
}

function gm_gtin(array $product): string
{
    foreach (['gtin', 'ean', 'barcode'] as $field) {
        $value = preg_replace('/\D+/', '', trim((string)($product[$field] ?? '')));
        if ($value !== '') {
            return $value;
        }
    }

    return '';
}

function gm_absolute_url(string $baseUrl, string $url): string
{
    $url = trim($url);
    if ($url === '') {
        return '';
    }
    if (str_starts_with($url, 'http://') || str_starts_with($url, 'https://')) {
        return $url;
    }
    return rtrim($baseUrl, '/') . '/' . ltrim($url, '/');
}

function gm_title(array $product, string $brand, string $name): string
{
    $name = trim($name) !== '' ? trim($name) : 'Produto Vivaliz';
    $category = trim((string)($product['category'] ?? ''));
    $sku = trim((string)($product['sku'] ?? $product['olist_product_id'] ?? $product['id'] ?? ''));
    $parts = [];

    if ($brand !== '' && stripos($name, $brand) === false) {
        $parts[] = $brand;
    }
    if ($category !== '' && stripos($name, $category) === false) {
        $parts[] = $category;
    }
    $parts[] = $name;
    if ($sku !== '' && preg_match('/^PRODUTO_\d+$/i', $sku) !== 1 && stripos($name, $sku) === false) {
        $parts[] = $sku;
    }

    $title = preg_replace('/\s+/', ' ', trim(implode(' ', array_filter($parts))));
    if (function_exists('mb_strimwidth')) {
        return mb_strimwidth((string)$title, 0, 150, '');
    }
    return substr((string)$title, 0, 150);
}

function gm_product_type(array $product): string
{
    $category = trim((string)($product['category'] ?? ''));
    return $category !== '' ? $category : 'Casa, jardim e utilidades';
}

function gm_human_name(array $product): string
{
    $name = trim((string)($product['name'] ?? ''));
    if ($name !== '' && preg_match('/^PRODUTO_\d+$/i', $name) !== 1) {
        return $name;
    }

    $description = trim(strip_tags((string)($product['description'] ?? '')));
    $description = preg_replace('/\s+/', ' ', $description) ?: '';
    $description = preg_replace('/\s*FOTOS MERAMENTE ILUSTRATIVAS\s*$/i', '', $description) ?: $description;

    return $description !== '' ? trim($description) : $name;
}

echo '<?xml version="1.0" encoding="UTF-8"?>' . PHP_EOL;
echo '<rss version="2.0" xmlns:g="http://base.google.com/ns/1.0">' . PHP_EOL;
echo '<channel>' . PHP_EOL;
echo '<title>ShopVivaliz Google Merchant Feed</title>' . PHP_EOL;
echo '<link>' . gm_xml($baseUrl) . '</link>' . PHP_EOL;
echo '<description>Feed de produtos da ShopVivaliz para Google Merchant Center.</description>' . PHP_EOL;

foreach ($products as $product) {
    if (!is_array($product)) {
        continue;
    }

    $id = trim((string)($product['sku'] ?? $product['olist_product_id'] ?? $product['id'] ?? ''));
    $slug = trim((string)($product['slug'] ?? ''));
    $name = svseo_human_name($product);
    $description = svseo_description($product);
    $image = trim((string)($product['image_url'] ?? ''));
    $price = (float)($product['price'] ?? 0);
    $stock = (int)($product['stock'] ?? 0);

    if ($id === '' || $slug === '' || $name === '' || $image === '' || $price <= 0) {
        continue;
    }

    $brand = svseo_brand($product);
    $gtin = gm_gtin($product);
    $link = $baseUrl . '/produto/' . $slug;
    $title = svseo_title($product);
    $productType = svseo_product_type($product, $name);
    $image = gm_absolute_url($baseUrl, $image);
    $additionalImages = [];
    foreach (is_array($product['images'] ?? null) ? $product['images'] : [] as $candidateImage) {
        $candidateImage = gm_absolute_url($baseUrl, (string)$candidateImage);
        if ($candidateImage === '' || $candidateImage === $image || in_array($candidateImage, $additionalImages, true)) {
            continue;
        }
        $additionalImages[] = $candidateImage;
        if (count($additionalImages) >= 10) {
            break;
        }
    }
    $availability = $stock > 0 ? 'in_stock' : 'out_of_stock';

    echo '<item>' . PHP_EOL;
    echo '<g:id>' . gm_xml($id) . '</g:id>' . PHP_EOL;
    echo '<title>' . gm_xml($title) . '</title>' . PHP_EOL;
    echo '<description>' . gm_xml($description) . '</description>' . PHP_EOL;
    echo '<link>' . gm_xml($link) . '</link>' . PHP_EOL;
    echo '<g:image_link>' . gm_xml($image) . '</g:image_link>' . PHP_EOL;
    foreach ($additionalImages as $additionalImage) {
        echo '<g:additional_image_link>' . gm_xml($additionalImage) . '</g:additional_image_link>' . PHP_EOL;
    }
    echo '<g:availability>' . gm_xml($availability) . '</g:availability>' . PHP_EOL;
    echo '<g:price>' . gm_xml(number_format($price, 2, '.', '') . ' BRL') . '</g:price>' . PHP_EOL;
    echo '<g:condition>new</g:condition>' . PHP_EOL;
    echo '<g:brand>' . gm_xml($brand) . '</g:brand>' . PHP_EOL;
    echo '<g:product_type>' . gm_xml($productType) . '</g:product_type>' . PHP_EOL;
    echo '<g:mpn>' . gm_xml($id) . '</g:mpn>' . PHP_EOL;
    if ($gtin !== '') {
        echo '<g:gtin>' . gm_xml($gtin) . '</g:gtin>' . PHP_EOL;
    } else {
        echo '<g:identifier_exists>no</g:identifier_exists>' . PHP_EOL;
    }
    echo '</item>' . PHP_EOL;
}

echo '</channel>' . PHP_EOL;
echo '</rss>' . PHP_EOL;

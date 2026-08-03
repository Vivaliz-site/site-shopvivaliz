<?php
declare(strict_types=1);

header('Content-Type: application/xml; charset=UTF-8');
header('Cache-Control: public, max-age=900, stale-while-revalidate=3600');
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

function gm_gtin(array $product): string
{
    foreach (['gtin', 'ean', 'barcode', 'codigo_barras'] as $field) {
        $value = preg_replace('/\D+/', '', trim((string)($product[$field] ?? '')));
        if (in_array(strlen((string)$value), [8, 12, 13, 14], true)) {
            return (string)$value;
        }
    }
    return '';
}

function gm_mpn(array $product): string
{
    foreach (['mpn', 'manufacturer_part_number', 'codigo_fabricante'] as $field) {
        $value = trim((string)($product[$field] ?? ''));
        if ($value !== '' && preg_match('/^PRODUTO_\d+$/i', $value) !== 1) {
            return svseo_trim_words($value, 70);
        }
    }
    return '';
}

function gm_id(array $product): string
{
    $raw = trim((string)($product['sku'] ?? $product['olist_product_id'] ?? $product['id'] ?? ''));
    $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $raw);
    $normalized = preg_replace('/[^A-Za-z0-9_-]+/', '-', is_string($ascii) ? $ascii : $raw) ?: '';
    return substr(trim($normalized, '-_'), 0, 50);
}

function gm_absolute_url(string $baseUrl, string $url): string
{
    $url = trim($url);
    if ($url === '') return '';
    if (str_starts_with($url, 'http://') || str_starts_with($url, 'https://')) return $url;
    return rtrim($baseUrl, '/') . '/' . ltrim($url, '/');
}

function gm_optional(array $product, array $fields, int $max = 100): string
{
    foreach ($fields as $field) {
        $value = trim((string)($product[$field] ?? ''));
        if ($value !== '') return svseo_trim_words($value, $max);
    }
    return '';
}

echo '<?xml version="1.0" encoding="UTF-8"?>' . PHP_EOL;
echo '<rss version="2.0" xmlns:g="http://base.google.com/ns/1.0">' . PHP_EOL;
echo '<channel>' . PHP_EOL;
echo '<title>ShopVivaliz Google Merchant Feed</title>' . PHP_EOL;
echo '<link>' . gm_xml($baseUrl) . '</link>' . PHP_EOL;
echo '<description>Produtos ativos da ShopVivaliz para o Google Merchant Center.</description>' . PHP_EOL;

foreach ($products as $product) {
    if (!is_array($product)) continue;

    $id = gm_id($product);
    $slug = trim((string)($product['slug'] ?? ''));
    $name = svseo_human_name($product);
    $description = svseo_description($product);
    $image = trim((string)($product['image_url'] ?? ''));
    $price = round((float)($product['price'] ?? 0), 2);
    $stock = (int)($product['stock'] ?? 0);

    if ($id === '' || $slug === '' || $name === '' || $image === '' || $price <= 0) continue;

    $brand = svseo_brand($product);
    $gtin = gm_gtin($product);
    $mpn = gm_mpn($product);
    $link = $baseUrl . '/produto/' . rawurlencode($slug);
    $title = svseo_title($product, 150);
    $productType = svseo_product_type($product, $name);
    $googleCategory = svseo_google_product_category($product);
    $image = gm_absolute_url($baseUrl, $image);
    $additionalImages = [];
    foreach (is_array($product['images'] ?? null) ? $product['images'] : [] as $candidateImage) {
        $candidateImage = gm_absolute_url($baseUrl, (string)$candidateImage);
        if ($candidateImage === '' || $candidateImage === $image || in_array($candidateImage, $additionalImages, true)) continue;
        $additionalImages[] = $candidateImage;
        if (count($additionalImages) >= 10) break;
    }

    $availability = $stock > 0 ? 'in_stock' : 'out_of_stock';
    $originalPrice = round((float)($product['original_price'] ?? $product['compare_at_price'] ?? $product['preco_de'] ?? 0), 2);
    $salePrice = $originalPrice > $price ? $price : 0.0;
    $regularPrice = $salePrice > 0 ? $originalPrice : $price;
    $color = gm_optional($product, ['color', 'cor']);
    $material = gm_optional($product, ['material']);
    $size = gm_optional($product, ['size', 'tamanho']);
    $itemGroupId = gm_optional($product, ['item_group_id', 'variant_group_id'], 50);

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
    echo '<g:price>' . gm_xml(number_format($regularPrice, 2, '.', '') . ' BRL') . '</g:price>' . PHP_EOL;
    if ($salePrice > 0) {
        echo '<g:sale_price>' . gm_xml(number_format($salePrice, 2, '.', '') . ' BRL') . '</g:sale_price>' . PHP_EOL;
    }
    echo '<g:condition>new</g:condition>' . PHP_EOL;
    echo '<g:brand>' . gm_xml($brand) . '</g:brand>' . PHP_EOL;
    echo '<g:product_type>' . gm_xml($productType) . '</g:product_type>' . PHP_EOL;
    if ($googleCategory !== '') echo '<g:google_product_category>' . gm_xml($googleCategory) . '</g:google_product_category>' . PHP_EOL;
    if ($gtin !== '') echo '<g:gtin>' . gm_xml($gtin) . '</g:gtin>' . PHP_EOL;
    if ($mpn !== '') echo '<g:mpn>' . gm_xml($mpn) . '</g:mpn>' . PHP_EOL;
    if ($gtin === '' && $mpn === '') echo '<g:identifier_exists>no</g:identifier_exists>' . PHP_EOL;
    if ($color !== '') echo '<g:color>' . gm_xml($color) . '</g:color>' . PHP_EOL;
    if ($material !== '') echo '<g:material>' . gm_xml($material) . '</g:material>' . PHP_EOL;
    if ($size !== '') echo '<g:size>' . gm_xml($size) . '</g:size>' . PHP_EOL;
    if ($itemGroupId !== '') echo '<g:item_group_id>' . gm_xml($itemGroupId) . '</g:item_group_id>' . PHP_EOL;
    echo '<g:custom_label_0>' . gm_xml($productType) . '</g:custom_label_0>' . PHP_EOL;
    echo '<g:custom_label_1>' . gm_xml(svseo_price_band($price)) . '</g:custom_label_1>' . PHP_EOL;
    echo '<g:custom_label_2>' . gm_xml($stock > 5 ? 'estoque-alto' : ($stock > 0 ? 'estoque-baixo' : 'sem-estoque')) . '</g:custom_label_2>' . PHP_EOL;
    echo '</item>' . PHP_EOL;
}

echo '</channel>' . PHP_EOL;
echo '</rss>' . PHP_EOL;

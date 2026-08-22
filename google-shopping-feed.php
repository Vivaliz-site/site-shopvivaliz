<?php
/**
 * Google Shopping / Merchant Center product feed.
 * Generated from the public ShopVivaliz catalog with manufacturer identifiers
 * recovered from the Tiny/Olist catalog cache when available.
 */

declare(strict_types=1);

header('Content-Type: application/xml; charset=utf-8');
header('Cache-Control: public, max-age=3600');

require_once __DIR__ . '/includes/catalog-runtime.php';
require_once __DIR__ . '/includes/catalog-image-enrich.php';
require_once __DIR__ . '/includes/site-settings.php';
require_once __DIR__ . '/includes/google-shopping-feed-utils.php';
require_once __DIR__ . '/includes/feed-cache.php';
svfc_start('google-shopping', 900, array_merge(svfc_default_catalog_dependencies(), ['google-shopping-feed.php', 'includes/google-shopping-feed-utils.php', 'includes/catalog-image-enrich.php', 'includes/feed-cache.php']));

$baseUrl = 'https://shopvivaliz.com.br';

// Rodada 5 (2026-08-19): faltava o mesmo enriquecimento de imagem que
// google-merchant-feed.php ja faz -- sem ele, quase todo produto ficava sem
// imagem valida e era descartado pelo filtro de imagem mais abaixo, fazendo
// o feed publicar zero (ou quase zero) produtos no Google Shopping. Ver R5-5
// no relatorio da Rodada 5.
$products = svcie_enrich_images(svcr_products());
$identifierMap = svgf_catalog_identifier_map(__DIR__);
$freeShipping = sv_free_shipping_config();
$hasUnconditionalFreeShipping = ($freeShipping['enabled'] ?? false)
    && (float)($freeShipping['threshold'] ?? 0) <= 0;

echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
echo '<rss version="2.0" xmlns:g="http://base.google.com/ns/1.0">' . "\n";
echo '<channel>' . "\n";
echo '<title>ShopVivaliz - Google Shopping Feed</title>' . "\n";
echo '<link>' . svgf_xml($baseUrl) . '</link>' . "\n";
echo '<description>Produtos ShopVivaliz para Google Shopping</description>' . "\n";
echo '<lastBuildDate>' . date('c') . '</lastBuildDate>' . "\n";

foreach ($products as $product) {
    if (!is_array($product)) continue;

    $sku = trim((string)($product['sku'] ?? ''));
    $name = svgf_limit((string)($product['name'] ?? ''), 150);
    $image = svgf_feed_absolute_url($baseUrl, (string)($product['image_url'] ?? ''));
    $price = (float)($product['price'] ?? 0);
    $category = svgf_limit((string)($product['category'] ?? 'Produtos'), 750);
    $stock = (int)($product['stock'] ?? 0);
    $slug = trim((string)($product['slug'] ?? ''));
    $url = svgf_feed_absolute_url($baseUrl, $slug !== '' ? '/produto/' . rawurlencode($slug) : '/catalogo');

    if ($sku === '' || $name === '' || $price <= 0 || $image === '' || $url === '') {
        continue;
    }

    $catalogIdentifiers = $identifierMap[$sku] ?? ['gtin' => '', 'mpn' => '', 'brand' => ''];
    $brand = svgf_limit((string)($product['brand'] ?? ''), 70);
    if ($brand === '') $brand = svgf_limit((string)($catalogIdentifiers['brand'] ?? ''), 70);

    $gtin = svgf_normalize_gtin($product['gtin'] ?? '');
    if ($gtin === '') $gtin = (string)($catalogIdentifiers['gtin'] ?? '');

    $mpn = svgf_limit((string)($product['mpn'] ?? ''), 70);
    if ($mpn === '') $mpn = svgf_limit((string)($catalogIdentifiers['mpn'] ?? ''), 70);

    $description = svgf_limit(
        (string)($product['description'] ?? 'Produto ShopVivaliz'),
        5000
    );
    if ($description === '') $description = $name;

    echo '<item>' . "\n";
    echo '<g:id>' . svgf_xml($sku) . '</g:id>' . "\n";
    echo '<title>' . svgf_xml($name) . '</title>' . "\n";
    echo '<description>' . svgf_xml($description) . '</description>' . "\n";
    echo '<g:link>' . svgf_xml($url) . '</g:link>' . "\n";
    echo '<g:image_link>' . svgf_xml($image) . '</g:image_link>' . "\n";

    $additionalImages = [];
    foreach (is_array($product['images'] ?? null) ? $product['images'] : [] as $candidate) {
        $candidate = svgf_feed_absolute_url($baseUrl, (string)$candidate);
        if ($candidate === '' || $candidate === $image) continue;
        if (!in_array($candidate, $additionalImages, true)) $additionalImages[] = $candidate;
        if (count($additionalImages) >= 10) break;
    }
    foreach ($additionalImages as $additionalImage) {
        echo '<g:additional_image_link>' . svgf_xml($additionalImage) . '</g:additional_image_link>' . "\n";
    }

    echo '<g:availability>' . ($stock > 0 ? 'in_stock' : 'out_of_stock') . '</g:availability>' . "\n";
    echo '<g:price>' . svgf_xml(number_format($price, 2, '.', '')) . ' BRL</g:price>' . "\n";
    echo '<g:condition>new</g:condition>' . "\n";

    if ($brand !== '') {
        echo '<g:brand>' . svgf_xml($brand) . '</g:brand>' . "\n";
    }
    if ($gtin !== '') {
        echo '<g:gtin>' . svgf_xml($gtin) . '</g:gtin>' . "\n";
    }
    if ($mpn !== '') {
        echo '<g:mpn>' . svgf_xml($mpn) . '</g:mpn>' . "\n";
    }

    // Keep the store taxonomy in product_type. Google product category is not
    // emitted unless there is an explicit mapping to Google's own taxonomy;
    // arbitrary internal categories must not be sent as google_product_category.
    if ($category !== '') {
        echo '<g:product_type>' . svgf_xml($category) . '</g:product_type>' . "\n";
    }

    if ($hasUnconditionalFreeShipping) {
        echo '<g:shipping>' . "\n";
        echo '<g:country>BR</g:country>' . "\n";
        echo '<g:price>0 BRL</g:price>' . "\n";
        echo '</g:shipping>' . "\n";
    }

    echo '</item>' . "\n";
}

echo '</channel>' . "\n";
echo '</rss>' . "\n";

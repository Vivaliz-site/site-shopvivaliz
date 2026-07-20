<?php
/**
 * Google Shopping Feed (Product Feed)
 * XML format for Google Merchant Center
 * Auto-generated from catalog
 */

header('Content-Type: application/xml; charset=utf-8');
header('Cache-Control: public, max-age=3600');

require_once __DIR__ . '/includes/catalog-runtime.php';
require_once __DIR__ . '/includes/site-settings.php';

$products = array_slice(sv_home_catalog_source_rows(), 0, 1000);

echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
echo '<rss version="2.0" xmlns:g="http://base.google.com/ns/1.0">' . "\n";
echo '<channel>' . "\n";
echo '<title>ShopVivaliz - Google Shopping Feed</title>' . "\n";
echo '<link>https://shopvivaliz.com.br</link>' . "\n";
echo '<description>Produtos ShopVivaliz para Google Shopping</description>' . "\n";
echo '<lastBuildDate>' . date('c') . '</lastBuildDate>' . "\n";

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

    echo '<item>' . "\n";
    echo '<g:id>' . htmlspecialchars($sku) . '</g:id>' . "\n";
    echo '<title>' . htmlspecialchars($name) . '</title>' . "\n";
    echo '<description>' . htmlspecialchars(substr($product['description'] ?? 'Produto ShopVivaliz', 0, 5000)) . '</description>' . "\n";
    echo '<g:link>' . htmlspecialchars($url) . '</g:link>' . "\n";
    echo '<g:image_link>' . htmlspecialchars($image) . '</g:image_link>' . "\n";
    echo '<g:availability>' . ($stock > 0 ? 'in stock' : 'out of stock') . '</g:availability>' . "\n";
    echo '<g:price>' . htmlspecialchars(number_format($price, 2, '.', '')) . ' BRL</g:price>' . "\n";
    echo '<g:currency>BRL</g:currency>' . "\n";
    echo '<g:brand>ShopVivaliz</g:brand>' . "\n";
    echo '<g:product_type>' . htmlspecialchars($category) . '</g:product_type>' . "\n";
    echo '<g:google_product_category>' . htmlspecialchars($category) . '</g:google_product_category>' . "\n";
    echo '<g:mpn>' . htmlspecialchars($sku) . '</g:mpn>' . "\n";

    // Shipping configuration
    echo '<g:shipping>' . "\n";
    echo '<g:country>BR</g:country>' . "\n";
    echo '<g:region>São Paulo</g:region>' . "\n";
    echo '<g:price>0 BRL</g:price>' . "\n";
    echo '</g:shipping>' . "\n";

    // Condition
    echo '<g:condition>new</g:condition>' . "\n";

    // Identifier exists
    echo '<g:identifier_exists>TRUE</g:identifier_exists>' . "\n";

    echo '</item>' . "\n";
}

echo '</channel>' . "\n";
echo '</rss>' . "\n";
?>

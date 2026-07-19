<?php
declare(strict_types=1);
header('Content-Type: application/xml; charset=UTF-8');
require_once __DIR__ . '/includes/catalog-runtime.php';

$official = __DIR__ . '/config/official-site.php';
$officialData = is_file($official) ? (@include $official) : [];
$base = is_array($officialData) && trim((string)($officialData['base_url'] ?? '')) !== ''
    ? rtrim((string)$officialData['base_url'], '/')
    : 'https://shopvivaliz.com.br';
$catalog = __DIR__ . '/storage/products-cache-ativos.json';
$catalogMTime = is_file($catalog) ? (int)@filemtime($catalog) : time();
$today   = date('Y-m-d', $catalogMTime > 0 ? $catalogMTime : time());
$products = svcr_products();

function sx(string $s): string { return htmlspecialchars($s, ENT_XML1, 'UTF-8'); }

echo '<?xml version="1.0" encoding="UTF-8"?>' . PHP_EOL;
echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:image="http://www.google.com/schemas/sitemap-image/1.1">' . PHP_EOL;

$pages = [
    ['loc' => '/',          'priority' => '1.0', 'freq' => 'daily'],
    ['loc' => '/catalogo/',  'priority' => '0.9', 'freq' => 'daily'],
    ['loc' => '/sobre/',     'priority' => '0.5', 'freq' => 'monthly'],
    ['loc' => '/contato/',   'priority' => '0.5', 'freq' => 'monthly'],
    ['loc' => '/faq/',       'priority' => '0.5', 'freq' => 'monthly'],
    ['loc' => '/termos', 'priority' => '0.3', 'freq' => 'yearly'],
    ['loc' => '/politica-privacidade/', 'priority' => '0.3', 'freq' => 'yearly'],
    ['loc' => '/politica-devolucoes', 'priority' => '0.3', 'freq' => 'yearly'],
    ['loc' => '/politica-entrega', 'priority' => '0.3', 'freq' => 'yearly'],
    ['loc' => '/blog/',      'priority' => '0.6', 'freq' => 'weekly'],
];

foreach ($pages as $p) {
    echo "  <url>\n";
    echo '    <loc>' . sx($base . $p['loc']) . "</loc>\n";
    echo "    <lastmod>{$today}</lastmod>\n";
    echo '    <changefreq>' . $p['freq'] . "</changefreq>\n";
    echo '    <priority>' . $p['priority'] . "</priority>\n";
    echo "  </url>\n";
}

$categories = [];
foreach ($products as $product) {
    if (!is_array($product)) continue;
    $category = trim((string)($product['category'] ?? ''));
    if ($category === '') continue;
    $categories[$category] = true;
}

ksort($categories);

foreach (array_keys($categories) as $category) {
    echo "  <url>\n";
    echo '    <loc>' . sx($base . '/catalogo/?categoria=' . rawurlencode($category)) . "</loc>\n";
    echo "    <lastmod>{$today}</lastmod>\n";
    echo "    <changefreq>weekly</changefreq>\n";
    echo "    <priority>0.7</priority>\n";
    echo "  </url>\n";
}

foreach ($products as $product) {
    if (!is_array($product)) continue;
    $slug = trim((string)($product['slug'] ?? ''));
    if ($slug === '') continue;
    $image = trim((string)($product['image_url'] ?? ''));
    $price = (float)($product['price'] ?? 0);
    if ($price <= 0 || $image === '') continue;
    echo "  <url>\n";
    echo '    <loc>' . sx("{$base}/produto/{$slug}") . "</loc>\n";
    echo "    <lastmod>{$today}</lastmod>\n";
    echo "    <changefreq>weekly</changefreq>\n";
    echo "    <priority>0.8</priority>\n";
    if ($image !== '') {
        echo "    <image:image>\n";
        echo '      <image:loc>' . sx($image) . "</image:loc>\n";
        echo '      <image:title>' . sx(trim((string)($product['name'] ?? ''))) . "</image:title>\n";
        echo "    </image:image>\n";
    }
    echo "  </url>\n";
}

echo '</urlset>' . PHP_EOL;

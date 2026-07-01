<?php
declare(strict_types=1);
header('Content-Type: application/xml; charset=UTF-8');
header('X-Robots-Tag: noindex');

$base    = 'https://dev.shopvivaliz.com.br';
$today   = date('Y-m-d');
$catalog = __DIR__ . '/api/catalog/fallback-products.json';
$products = is_file($catalog) ? (json_decode((string)file_get_contents($catalog), true) ?: []) : [];

function sx(string $s): string { return htmlspecialchars($s, ENT_XML1, 'UTF-8'); }

echo '<?xml version="1.0" encoding="UTF-8"?>' . PHP_EOL;
echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . PHP_EOL;

$pages = [
    ['loc' => '/',          'priority' => '1.0', 'freq' => 'daily'],
    ['loc' => '/catalogo',  'priority' => '0.9', 'freq' => 'daily'],
    ['loc' => '/sobre',     'priority' => '0.5', 'freq' => 'monthly'],
    ['loc' => '/contato',   'priority' => '0.5', 'freq' => 'monthly'],
    ['loc' => '/blog',      'priority' => '0.6', 'freq' => 'weekly'],
];

foreach ($pages as $p) {
    echo "  <url>\n";
    echo '    <loc>' . sx($base . $p['loc']) . "</loc>\n";
    echo "    <lastmod>{$today}</lastmod>\n";
    echo '    <changefreq>' . $p['freq'] . "</changefreq>\n";
    echo '    <priority>' . $p['priority'] . "</priority>\n";
    echo "  </url>\n";
}

foreach ($products as $product) {
    if (!is_array($product)) continue;
    $slug = trim((string)($product['slug'] ?? ''));
    if ($slug === '') continue;
    $image = trim((string)($product['image_url'] ?? ''));
    echo "  <url>\n";
    echo '    <loc>' . sx("{$base}/produto/{$slug}") . "</loc>\n";
    echo "    <lastmod>{$today}</lastmod>\n";
    echo "    <changefreq>weekly</changefreq>\n";
    echo "    <priority>0.8</priority>\n";
    if ($image !== '') {
        echo "    <image:image xmlns:image=\"http://www.google.com/schemas/sitemap-image/1.1\">\n";
        echo '      <image:loc>' . sx($image) . "</image:loc>\n";
        echo '      <image:title>' . sx(trim((string)($product['name'] ?? ''))) . "</image:title>\n";
        echo "    </image:image>\n";
    }
    echo "  </url>\n";
}

echo '</urlset>' . PHP_EOL;

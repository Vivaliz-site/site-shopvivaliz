<?php
declare(strict_types=1);

header('Content-Type: application/xml; charset=UTF-8');
header('Cache-Control: public, max-age=900, stale-while-revalidate=3600');

require_once __DIR__ . '/includes/catalog-runtime.php';
require_once __DIR__ . '/includes/blog-article-repository.php';
require_once __DIR__ . '/includes/catalog-image-enrich.php';

$official = __DIR__ . '/config/official-site.php';
$officialData = is_file($official) ? (@include $official) : [];
$base = is_array($officialData) && trim((string)($officialData['base_url'] ?? '')) !== ''
    ? rtrim((string)$officialData['base_url'], '/')
    : 'https://shopvivaliz.com.br';
$catalog = __DIR__ . '/storage/products-cache-ativos.json';
$catalogMTime = is_file($catalog) ? (int)@filemtime($catalog) : time();
$today = date('Y-m-d', $catalogMTime > 0 ? $catalogMTime : time());
// Rodada 2 (2026-08-18): svcr_products() sozinho nao enriquece image_url --
// isso descartava os 176 produtos do sitemap (ver 'continue' abaixo, que exige
// imagem). O enriquecimento reaproveita o mesmo mapa SKU->imagem ja usado no
// catalogo publico, sem tocar em price/stock/description.
$products = svcie_enrich_images(svcr_products());
$blogRepository = BlogArticleRepository::fromApplicationDatabase();

function sx(string $value): string
{
    return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
}

function sitemap_date(mixed $value, string $fallback): string
{
    if (is_int($value) || (is_string($value) && ctype_digit($value))) {
        $timestamp = (int)$value;
    } else {
        $timestamp = strtotime(trim((string)$value));
    }
    return $timestamp && $timestamp > 0 ? gmdate('Y-m-d', $timestamp) : $fallback;
}

function sitemap_absolute_image(string $base, string $image): string
{
    $image = trim($image);
    if ($image === '') return '';
    if (str_starts_with($image, 'https://')) return $image;
    if (str_starts_with($image, 'http://')) return '';
    if (str_starts_with($image, '//')) return '';
    return $base . '/' . ltrim($image, '/');
}

/** @var array<string, true> $emitted */
$emitted = [];

function sitemap_emit(
    string $loc,
    string $lastmod,
    string $changefreq,
    string $priority,
    string $image = '',
    string $imageTitle = ''
): void {
    global $emitted;
    if (isset($emitted[$loc])) return;
    $emitted[$loc] = true;

    echo "  <url>\n";
    echo '    <loc>' . sx($loc) . "</loc>\n";
    echo '    <lastmod>' . sx($lastmod) . "</lastmod>\n";
    echo '    <changefreq>' . sx($changefreq) . "</changefreq>\n";
    echo '    <priority>' . sx($priority) . "</priority>\n";
    if ($image !== '') {
        echo "    <image:image>\n";
        echo '      <image:loc>' . sx($image) . "</image:loc>\n";
        if ($imageTitle !== '') {
            echo '      <image:title>' . sx($imageTitle) . "</image:title>\n";
        }
        echo "    </image:image>\n";
    }
    echo "  </url>\n";
}

echo '<?xml version="1.0" encoding="UTF-8"?>' . PHP_EOL;
echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:image="http://www.google.com/schemas/sitemap-image/1.1">' . PHP_EOL;

// Physical public directories are canonicalized by Apache DirectorySlash.
// Emit their trailing-slash form directly so crawlers never spend budget on
// 301 hops and every sitemap URL is the final 200 destination.
$pages = [
    ['loc' => '/', 'priority' => '1.0', 'freq' => 'daily'],
    ['loc' => '/catalogo/', 'priority' => '0.9', 'freq' => 'daily'],
    ['loc' => '/sobre/', 'priority' => '0.5', 'freq' => 'monthly'],
    ['loc' => '/contato/', 'priority' => '0.5', 'freq' => 'monthly'],
    ['loc' => '/faq/', 'priority' => '0.5', 'freq' => 'monthly'],
    ['loc' => '/avaliacoes.php', 'priority' => '0.4', 'freq' => 'weekly'],
    ['loc' => '/termos/', 'priority' => '0.3', 'freq' => 'yearly'],
    ['loc' => '/politica-privacidade/', 'priority' => '0.3', 'freq' => 'yearly'],
    ['loc' => '/politica-devolucoes/', 'priority' => '0.3', 'freq' => 'yearly'],
    ['loc' => '/politica-entrega/', 'priority' => '0.3', 'freq' => 'yearly'],
    ['loc' => '/blog/', 'priority' => '0.7', 'freq' => 'weekly'],
];

foreach ($pages as $page) {
    sitemap_emit(
        $base . $page['loc'],
        $today,
        (string)$page['freq'],
        (string)$page['priority']
    );
}

foreach ($blogRepository->published('', '', 500) as $article) {
    $slug = trim((string)($article['slug'] ?? ''));
    if ($slug === '') continue;
    $lastmod = sitemap_date($article['updated_at'] ?? $article['published_at'] ?? '', $today);
    $image = sitemap_absolute_image($base, (string)($article['image'] ?? ''));
    sitemap_emit(
        $base . '/blog/' . rawurlencode($slug),
        $lastmod,
        'monthly',
        '0.7',
        $image,
        trim((string)($article['title'] ?? ''))
    );
}

// Facetas com query string ficam fora do sitemap. Elas permanecem navegáveis,
// mas o sitemap canônico contém apenas páginas estáveis, artigos e produtos.
foreach ($products as $product) {
    if (!is_array($product)) continue;
    $slug = trim((string)($product['slug'] ?? ''));
    $name = trim((string)($product['name'] ?? ''));
    $imageSrc = trim((string)($product['image_url'] ?? ''));
    if ($imageSrc === '' && !empty($product['images'][0])) {
        $imageSrc = (string)$product['images'][0];
    }
    $image = sitemap_absolute_image($base, $imageSrc);
    $price = (float)($product['price'] ?? 0);
    $stock = (int)($product['stock'] ?? 0);
    // Search Console was accumulating stale product URLs. The sitemap must
    // advertise only pages that are indexable purchase/detail destinations:
    // current slug, name, positive price, positive stock and a safe HTTPS image.
    // Out-of-stock or incomplete rows remain reachable from internal flows when
    // needed, but they should not be submitted for indexing.
    if ($slug === '' || $name === '' || $price <= 0 || $stock <= 0 || $image === '') continue;

    $lastmod = sitemap_date(
        $product['updated_at']
            ?? $product['modified_at']
            ?? $product['last_update']
            ?? $catalogMTime,
        $today
    );
    sitemap_emit(
        $base . '/produto/' . rawurlencode($slug),
        $lastmod,
        'weekly',
        '0.8',
        $image,
        $name
    );
}

echo '</urlset>' . PHP_EOL;
<?php
declare(strict_types=1);

$base = getenv('SV_PUBLIC_BASE_URL') ?: 'https://shopvivaliz.com.br';
$errors = [];

function gr_fetch(string $url): string {
    $ctx = stream_context_create(['http' => ['timeout' => 20, 'ignore_errors' => true]]);
    $body = @file_get_contents($url, false, $ctx, 0, 524288);
    if (!is_string($body)) throw new RuntimeException('fetch_failed:' . $url);
    return $body;
}

foreach (['google-shopping-feed.php', 'google-merchant-feed.php'] as $path) {
    $body = gr_fetch(rtrim($base, '/') . '/' . $path . '?smoke=' . time());
    $items = substr_count($body, '<item>');
    if ($items < 20) $errors[] = $path . ':too_few_items_in_sample:' . $items;
    if (!str_contains($body, 'https://s3.amazonaws.com/tiny-anexos-')) $errors[] = $path . ':missing_erp_tiny_images';
    if (str_contains($body, '/uploads/catalog-fixed/') || str_contains($body, '/storage/')) $errors[] = $path . ':local_or_manual_product_image';
    foreach (['<g:id>', '<g:price>', '<g:availability>', '<g:image_link>', '<g:link>'] as $needle) {
        if (!str_contains($body, $needle)) $errors[] = $path . ':missing:' . $needle;
    }
}

$catalog = json_decode(gr_fetch(rtrim($base, '/') . '/api/catalog/products.php?limit=24&available=1&no_cache=1'), true);
if (!is_array($catalog) || empty($catalog['products'])) $errors[] = 'catalog_api_no_products';
foreach ((array)($catalog['products'] ?? []) as $p) {
    if ((int)($p['stock'] ?? 0) <= 0) $errors[] = 'catalog_available_without_stock:' . (string)($p['sku'] ?? '');
    if ((float)($p['price'] ?? 0) <= 0) $errors[] = 'catalog_available_without_price:' . (string)($p['sku'] ?? '');
    if (trim((string)($p['image_url'] ?? '')) === '') $errors[] = 'catalog_available_without_image:' . (string)($p['sku'] ?? '');
}

$sitemap = gr_fetch(rtrim($base, '/') . '/sitemap.xml?smoke=' . time());
if (substr_count($sitemap, '<url>') < 20) $errors[] = 'sitemap_too_few_urls_in_sample';
if (!str_contains($sitemap, '/produto/')) $errors[] = 'sitemap_missing_products';

if ($errors) {
    fwrite(STDERR, "GOOGLE_READINESS_SMOKE_FAILED\n" . implode("\n", array_unique($errors)) . "\n");
    exit(1);
}

echo "GOOGLE_READINESS_SMOKE_OK feed_samples catalog_ok sitemap_ok\n";

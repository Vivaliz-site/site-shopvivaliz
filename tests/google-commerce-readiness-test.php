<?php
declare(strict_types=1);

$base = getenv('SV_PUBLIC_BASE_URL') ?: 'https://shopvivaliz.com.br';
$errors = [];
function fetch_body(string $url): string {
    $ctx = stream_context_create(['http' => ['timeout' => 25, 'ignore_errors' => true]]);
    $body = @file_get_contents($url, false, $ctx);
    if (!is_string($body) || $body === '') throw new RuntimeException('empty_response:' . $url);
    return $body;
}
function check_feed(string $name, string $url, array &$errors): void {
    try { $body = fetch_body($url . (str_contains($url, '?') ? '&' : '?') . 'qa=' . time()); }
    catch (Throwable $e) { $errors[] = $name . ':fetch_failed:' . $e->getMessage(); return; }
    $items = substr_count($body, '<item>');
    if ($items < 100) $errors[] = $name . ':too_few_items:' . $items;
    foreach (['<g:id>', '<g:image_link>https://s3.amazonaws.com/tiny-anexos-', '<g:price>', '<g:availability>in_stock</g:availability>'] as $needle) {
        if (!str_contains($body, $needle)) $errors[] = $name . ':missing:' . $needle;
    }
}
check_feed('google-shopping-feed', rtrim($base, '/') . '/google-shopping-feed.php', $errors);
check_feed('google-merchant-feed', rtrim($base, '/') . '/google-merchant-feed.php', $errors);
try {
    $catalog = json_decode(fetch_body(rtrim($base, '/') . '/api/catalog/products.php?limit=24&available=1&no_cache=1'), true);
    $products = is_array($catalog['products'] ?? null) ? $catalog['products'] : [];
    if (count($products) < 10) $errors[] = 'catalog:too_few_available_products:' . count($products);
    foreach ($products as $p) {
        if ((float)($p['price'] ?? 0) <= 0) $errors[] = 'catalog:invalid_price:' . (string)($p['sku'] ?? '');
        if ((int)($p['stock'] ?? 0) <= 0) $errors[] = 'catalog:invalid_stock:' . (string)($p['sku'] ?? '');
        if (!str_starts_with((string)($p['image_url'] ?? ''), 'https://s3.amazonaws.com/tiny-anexos-')) $errors[] = 'catalog:non_erp_image:' . (string)($p['sku'] ?? '');
    }
} catch (Throwable $e) { $errors[] = 'catalog:fetch_failed:' . $e->getMessage(); }
if ($errors !== []) {
    fwrite(STDERR, "GOOGLE_COMMERCE_READINESS_FAILED\n" . implode("\n", array_unique($errors)) . "\n");
    exit(1);
}
echo "GOOGLE_COMMERCE_READINESS_OK\n";

<?php
declare(strict_types=1);

$base = getenv('SV_GOOGLE_PUBLIC_BASE_URL') ?: 'https://shopvivaliz.com.br';
$errors = [];

function svgr_fetch(string $url): string {
    $ctx = stream_context_create(['http' => ['timeout' => 25, 'header' => "User-Agent: ShopVivalizGoogleReadiness/1.0\r\n"]]);
    $raw = @file_get_contents($url, false, $ctx);
    if (!is_string($raw) || $raw === '') {
        throw new RuntimeException('fetch_failed:' . $url);
    }
    return $raw;
}

function svgr_assert_allowed_image_hosts(string $xml, string $path, array &$errors): void {
    if (preg_match_all('~<g:image_link>([^<]+)</g:image_link>~', $xml, $matches)) {
        foreach ($matches[1] as $url) {
            $host = strtolower((string)(parse_url($url, PHP_URL_HOST) ?: ''));
            $pathPart = (string)(parse_url($url, PHP_URL_PATH) ?: '');
            $allowedTiny = $host === 's3.amazonaws.com' && str_starts_with($pathPart, '/tiny-anexos-');
            $allowedSite = preg_replace('/^www\./', '', $host) === 'shopvivaliz.com.br';
            if (!$allowedTiny && !$allowedSite) {
                $errors[] = $path . ':disallowed_image_host:' . $host;
            }
        }
    }
}

function svgr_count_items(string $xml): int {
    return substr_count($xml, '<item>');
}

foreach (['/google-shopping-feed.php', '/google-merchant-feed.php'] as $path) {
    $xml = svgr_fetch(rtrim($base, '/') . $path . '?test_cb=' . time());
    $items = svgr_count_items($xml);
    if ($items < 100) $errors[] = $path . ':too_few_items:' . $items;
    foreach (['<g:id>', '<title>', '<g:link>', '<g:image_link>', '<g:price>', '<g:availability>'] as $tag) {
        if (!str_contains($xml, $tag)) $errors[] = $path . ':missing_tag:' . $tag;
    }
    if (!preg_match('~<g:image_link>https://s3\.amazonaws\.com/tiny-anexos-[^<]+</g:image_link>~', $xml)) {
        $errors[] = $path . ':no_erp_tiny_images';
    }
    svgr_assert_allowed_image_hosts($xml, $path, $errors);
}

$api = json_decode(svgr_fetch(rtrim($base, '/') . '/api/catalog/products.php?limit=24&available=1&no_cache=1'), true);
if (!is_array($api) || empty($api['products']) || (int)($api['total'] ?? 0) < 100) {
    $errors[] = 'catalog_api_unhealthy';
}

$sitemap = svgr_fetch(rtrim($base, '/') . '/sitemap.xml?test_cb=' . time());
if (substr_count($sitemap, '<url>') < 100) $errors[] = 'sitemap_too_small';
if (!str_contains($sitemap, '<loc>https://shopvivaliz.com.br/produto/')) $errors[] = 'sitemap_missing_products';

if ($errors) {
    fwrite(STDERR, "GOOGLE_READINESS_PUBLIC_FAILED\n" . implode("\n", array_unique($errors)) . "\n");
    exit(1);
}

echo "GOOGLE_READINESS_PUBLIC_OK\n";

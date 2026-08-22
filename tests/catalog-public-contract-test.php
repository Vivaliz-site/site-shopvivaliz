<?php
declare(strict_types=1);
$base = getenv('SV_CATALOG_BASE_URL') ?: 'https://shopvivaliz.com.br';
function fetch_json(string $url): array {
    $raw = @file_get_contents($url);
    $json = json_decode(is_string($raw) ? $raw : '', true);
    if (!is_array($json)) throw new RuntimeException('invalid_json:' . $url);
    return $json;
}
$errors = [];
$first = fetch_json(rtrim($base, '/') . '/api/catalog/products.php?limit=12&offset=0&available=1&no_cache=1');
$second = fetch_json(rtrim($base, '/') . '/api/catalog/products.php?limit=12&offset=12&available=1&no_cache=1');
$search = fetch_json(rtrim($base, '/') . '/api/catalog/products.php?q=decore&limit=20&available=1&no_cache=1');
foreach ([['first',$first], ['second',$second], ['search',$search]] as [$label,$payload]) {
    $products = is_array($payload['products'] ?? null) ? $payload['products'] : [];
    if ($products === []) $errors[] = $label . ':no_products';
    foreach ($products as $p) {
        $sku = trim((string)($p['sku'] ?? ''));
        if ($sku === '') $errors[] = $label . ':product_without_sku';
        if (strtolower((string)($p['status'] ?? 'active')) !== 'active') $errors[] = $sku . ':not_active_in_available_feed';
        if ((int)($p['stock'] ?? 0) <= 0) $errors[] = $sku . ':not_in_stock_in_available_feed';
        if ((float)($p['price'] ?? 0) <= 0) $errors[] = $sku . ':invalid_price_in_available_feed';
        if (trim((string)($p['image_url'] ?? '')) === '') $errors[] = $sku . ':missing_image_in_available_feed';
        if (empty($p['slug'])) $errors[] = $sku . ':missing_slug_in_available_feed';
    }
}
$ids1 = array_map(static fn($p): string => (string)($p['sku'] ?? ''), $first['products'] ?? []);
$ids2 = array_map(static fn($p): string => (string)($p['sku'] ?? ''), $second['products'] ?? []);
if (array_intersect($ids1, $ids2) !== []) $errors[] = 'pagination_overlap_between_page_1_and_2';
if ((int)($first['total'] ?? 0) < count($first['products'] ?? [])) $errors[] = 'total_less_than_page_count';
foreach (($search['products'] ?? []) as $p) {
    $hay = strtolower((string)($p['name'] ?? '') . ' ' . (string)($p['sku'] ?? '') . ' ' . (string)($p['category'] ?? ''));
    if (!str_contains($hay, 'decore')) $errors[] = 'search_result_not_matching_decore:' . (string)($p['sku'] ?? '');
}
if ($errors !== []) {
    fwrite(STDERR, "CATALOG_PUBLIC_CONTRACT_FAILED\n" . implode("\n", array_unique($errors)) . "\n");
    exit(1);
}
echo 'CATALOG_PUBLIC_CONTRACT_OK total=' . (int)($first['total'] ?? 0) . PHP_EOL;

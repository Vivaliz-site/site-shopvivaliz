<?php
declare(strict_types=1);

function assert_test(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
    fwrite(STDOUT, "PASS: {$message}\n");
}

ob_start();
$_GET['slug'] = 'test-suppress-page-run';
require_once __DIR__ . '/../produto.php';
ob_end_clean();

// 1. Check resolution of historical Google Ads sitelink slugs
$testSlugs = [
    'rodo-vedante-borracha-friso-porta-aluminio-80cm-vivaliz-ved80nh' => 'ved-80-nh',
    'rodo-vedante-borracha-friso-porta-aluminio-70cm-vivaliz-ved70' => 'ved-70',
    'rodo-vedante-borracha-friso-porta-aluminio-100cm-vivaliz-rodoved100' => 'RODOVED100',
    'kit-2-rodo-vedante-borracha-friso-porta-aluminio-70cm-kit70' => 'kit-70',
];

foreach ($testSlugs as $slug => $expectedSku) {
    $found = sv_product_find_slug($slug);
    $resolvedSku = (string)($found['sku'] ?? '');
    assert_test(
        strcasecmp($resolvedSku, $expectedSku) === 0,
        "Slug '{$slug}' must resolve to SKU '{$expectedSku}' (got '{$resolvedSku}')"
    );
}

// 2. Check query string preservation during canonical redirect
$_SERVER['QUERY_STRING'] = 'slug=rodo-vedante-porta-aluminio-vivaliz-80-cm-ved80nh&gclid=TestGclid123&utm_source=google&utm_medium=cpc';
$redirect = sv_product_canonical_slug_redirect(
    'rodo-vedante-porta-aluminio-vivaliz-80-cm-ved80nh',
    'rodo-vedante-porta-alumínio-vivaliz-80-cm-ved80nh'
);

assert_test($redirect !== null, "Canonical redirect path must not be null when slugs differ");
assert_test(str_contains($redirect, 'gclid=TestGclid123'), "Redirect path must preserve gclid parameter: {$redirect}");
assert_test(str_contains($redirect, 'utm_source=google'), "Redirect path must preserve utm_source parameter: {$redirect}");
assert_test(!str_contains($redirect, 'slug='), "Redirect path must not leak internal rewrite slug parameter: {$redirect}");

// 3. Check resolution in produto-slug-route.php controller functions
require_once __DIR__ . '/../includes/product-historical-aliases.php';
require_once __DIR__ . '/../produto-slug-route.php';

foreach ($testSlugs as $slug => $expectedSku) {
    $row = sv_product_route_catalog_row_by_sku_suffix($slug);
    $resolvedSku = (string)($row['sku'] ?? '');
    assert_test(
        strcasecmp($resolvedSku, $expectedSku) === 0,
        "produto-slug-route SKU suffix '{$slug}' must resolve to SKU '{$expectedSku}' (got '{$resolvedSku}')"
    );
}

fwrite(STDOUT, "All product slug canonical redirect tests passed successfully.\n");

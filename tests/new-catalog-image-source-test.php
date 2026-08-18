<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$errors = [];

$feed = (string)file_get_contents($root . '/google-merchant-feed.php');
$source = (string)file_get_contents($root . '/includes/new-catalog-image-source.php');
$route = (string)file_get_contents($root . '/produto-slug-route.php');
$htaccess = (string)file_get_contents($root . '/.htaccess');

if (!str_contains($feed, 'svncis_enrich_direct_olist_images')) {
    $errors[] = 'merchant_feed_does_not_use_direct_current_catalog_images';
}
if (str_contains($feed, 'catalog-image-enrich.php') || str_contains($feed, 'svcie_enrich_images')) {
    $errors[] = 'merchant_feed_has_legacy_image_fallback';
}
if (!str_contains($source, "source = 'erp_olist_export'") || !str_contains($source, "source LIKE 'olist_visual_api:%'")) {
    $errors[] = 'image_source_is_not_restricted_to_direct_olist_catalog';
}
if (str_contains($source, 'public_shopvivaliz_site') || str_contains($source, 'catalog_family_bridge')) {
    $errors[] = 'image_source_accepts_non_direct_catalog_image';
}
if (str_contains($route, 'detail_json') || str_contains($route, 'products-cache.json')) {
    $errors[] = 'product_route_still_reads_retired_catalog_mapping';
}
if (!str_contains($route, 'sv_product_route_catalog_redirect')) {
    $errors[] = 'retired_numeric_route_does_not_redirect_to_current_catalog';
}
if (!str_contains($htaccess, '/catalogo')) {
    $errors[] = 'legacy_storefront_routes_do_not_target_current_catalog';
}
if (is_file($root . '/produto-numeric-route.php') || is_file($root . '/ops/cloudflare-www-redirect-apply.json')) {
    $errors[] = 'retired_storefront_artifact_still_present';
}

if ($errors !== []) {
    fwrite(STDERR, implode(PHP_EOL, $errors) . PHP_EOL);
    exit(1);
}

echo "new-catalog image source and retired route checks passed\n";

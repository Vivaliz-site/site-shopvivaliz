<?php
declare(strict_types=1);

// Re-run this contract on main after canonical/indexing fixes are published.
$root = dirname(__DIR__);
$catalog = file_get_contents($root . '/catalogo.php');
$product = file_get_contents($root . '/produto.php');

if (!is_string($catalog) || !is_string($product)) {
    fwrite(STDERR, "Unable to read storefront SEO sources\n");
    exit(1);
}

$requiredCatalog = [
    'return sv_catalog_base_url() . \'/catalogo/\' . ($query !== \'\' ? \'?\' . $query : \'\');',
    'return \'/catalogo/\' . ($qs !== \'\' ? \'?\' . $qs : \'\');',
];
foreach ($requiredCatalog as $needle) {
    if (!str_contains($catalog, $needle)) {
        fwrite(STDERR, "Catalog canonical/page URLs must use /catalogo/\n");
        exit(1);
    }
}

$requiredProduct = [
    'sv_product_canonical_slug_redirect',
    'header(\'Location: \' . $redirectPath, true, 301);',
    "http_response_code(404);",
    'noindex,follow',
];
foreach ($requiredProduct as $needle) {
    if (!str_contains($product, $needle)) {
        fwrite(STDERR, "Product canonical redirect or 404 noindex contract is missing: {$needle}\n");
        exit(1);
    }
}

fwrite(STDOUT, "google-indexing-canonical-regression-test: ok\n");

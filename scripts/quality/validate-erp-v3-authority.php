<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$failures = [];

// Rule: ERP/Tiny API v3 is authoritative for every product field that has an
// ERP equivalent. Enrichment is allowed, but if the enriched field has an ERP
// equivalent it must be written back to ERP through API v3 and then return to
// the public site through the v3 sync. Site-only information is allowed only
// when there is no ERP equivalent and it does not overwrite/complete product
// registration fields.
$publicFiles = [
    'includes/catalog-runtime.php',
    'includes/product-price-enrich.php',
    'includes/catalog-image-enrich.php',
    'api/catalog/products.php',
    'catalogo.php',
    'produto.php',
    'index.php',
];

$forbidden = [
    'api2/' => 'Tiny/Olist API v2 must not be used for product registration data',
    'TOKEN' . '_API_' . 'OLIST' => 'static/v2 token must not be used for product registration data',
    'uploads/olist_imagens_site_mapeamento.csv' => 'CSV/local mapping must not enrich ERP-equivalent product fields such as images',
    'uploads/catalog-fixed' => 'manual fixed catalog images are forbidden',
    'storage/products-cache.json' => 'historical product snapshot must not enrich ERP-equivalent product fields',
    'api/catalog/fallback-products.json' => 'fallback-products snapshot must not enrich ERP-equivalent product fields',
    'FROM products p' => 'local products table must not enrich ERP-equivalent product fields',
    'JOIN products p' => 'local products table must not enrich ERP-equivalent product fields',
    'p.image_url' => 'local product image fallback is forbidden',
];

$allowedCommentFiles = [];
foreach ($publicFiles as $file) {
    $path = $root . '/' . $file;
    if (!is_file($path)) {
        $failures[] = "$file missing";
        continue;
    }
    $text = (string)file_get_contents($path);
    foreach ($forbidden as $needle => $reason) {
        if (stripos($text, $needle) !== false) {
            $failures[] = "$file contains forbidden '{$needle}': {$reason}";
        }
    }
}

foreach (['olist/sync-products.php', 'olist/sync-on-webhook.php'] as $file) {
    $text = is_file($root . '/' . $file) ? (string)file_get_contents($root . '/' . $file) : '';
    if ($text === '') {
        $failures[] = "$file missing";
        continue;
    }
    if (stripos($text, 'public-api/v3') === false) {
        $failures[] = "$file does not call Tiny/Olist public-api/v3";
    }
    if (preg_match('~api2/|produtos\\.pesquisa\\.php|TOKEN' . '_API_' . 'OLIST~i', $text) === 1) {
        $failures[] = "$file contains executable legacy API/legacy-static-token reference";
    }
}

if ($failures !== []) {
    fwrite(STDERR, "ERP v3 authority validation failed:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "ERP v3 authority validation OK: ERP-equivalent fields are v3-synced; enrichment must mirror to ERP before becoming public canonical data\n";

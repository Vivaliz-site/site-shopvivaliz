<?php
declare(strict_types=1);

function sv_catalog_test_assert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$endpoint = dirname(__DIR__) . '/api/catalog/products.php';
$source = (string)file_get_contents($endpoint);
sv_catalog_test_assert($source !== '', 'Catalog API source should be readable.');

$marker = "require_once svcat_root() . '/includes/catalog-runtime.php';";
$position = strpos($source, $marker);
sv_catalog_test_assert($position !== false, 'Catalog API function boundary should remain identifiable.');

$definitions = substr($source, 0, (int)$position);
$definitions = preg_replace('/^<\?php\s*/', '', $definitions, 1) ?? '';
// The endpoint declares strict_types at file scope. The test file is already
// strict, so remove that declaration before evaluating only the pure helpers.
$definitions = preg_replace('/declare\(strict_types=1\);\s*/', '', $definitions, 1) ?? $definitions;
eval($definitions);

$fercarCart = [
    'sku' => 'C06PT',
    'name' => 'Carrinho Fechado Ferramentas C06 3 Gavetas Preto Cinza Fercar',
    'category' => 'Caixas de Ferramentas',
    'description' => 'Carrinho móvel para oficina.',
    'olist_product_id' => '353692956',
    'id' => '353692956',
];
$unrelatedTool = [
    'sku' => 'TEST-ALICATE',
    'name' => 'Alicate Universal Profissional Gedore',
    'category' => 'Ferramentas',
    'description' => 'Alicate para manutenção.',
    'olist_product_id' => '1',
    'id' => '1',
];

sv_catalog_test_assert(
    svcat_product_matches_query($fercarCart, 'carrinho fercar'),
    'Multi-word paid landing query should match non-contiguous product words.'
);
sv_catalog_test_assert(
    svcat_product_matches_query($fercarCart, 'carrinhos fercar'),
    'Simple plural should match its singular catalog term.'
);
sv_catalog_test_assert(
    svcat_product_matches_query($fercarCart, 'carrinho de ferramentas fercar'),
    'Portuguese stopwords should not break purchase-intent searches.'
);
sv_catalog_test_assert(
    !svcat_product_matches_query($unrelatedTool, 'carrinho fercar'),
    'Brand/tool category overlap must not broaden the paid landing to unrelated products.'
);

// A standalone connector must not erase the search and show the full
// inventory. The same predicate serves ads landings and storefront search.
sv_catalog_test_assert(
    svcat_search_terms('para') === ['PARA'],
    'Standalone connector PARA must not silently broaden to all SKUs.'
);
sv_catalog_test_assert(
    svcat_search_terms('de para') === ['DE', 'PARA'],
    'Connector-only multiword queries must not silently match all SKUs.'
);
sv_catalog_test_assert(
    svcat_search_terms('rodo para porta') === ['RODO', 'PORTA'],
    'Meaningful multiword purchase intent keeps ignoring connectors.'
);
sv_catalog_test_assert(
    svcat_search_terms('') === [],
    'Only an empty search should display the full catalog.'
);
$irrelevantProduct = ['sku' => 'MRT-01', 'name' => 'Martelo Aco',
    'description' => 'Manual reforcado', 'category' => 'Ferramentas'];
sv_catalog_test_assert(
    !svcat_product_matches_query($irrelevantProduct, 'para'),
    'Unrelated product must not match standalone connector search.'
);
sv_catalog_test_assert(
    !svcat_product_matches_query($irrelevantProduct, '!!!'),
    'Punctuation-only nonempty search must not match every product.'
);

sv_catalog_test_assert(
    str_contains($source, "'slug' => trim((string)(\$row['slug'] ?? ''))"),
    'Catalog API must expose the canonical runtime slug so JS does not invent a different product URL.'
);
sv_catalog_test_assert(
    str_contains($source, "'available_only' => \$availableOnly ? '1' : '0'"),
    'Storefront availability mode must participate in the response cache key.'
);
sv_catalog_test_assert(
    str_contains($source, "\$isStorefrontCatalog"),
    'Catalog API must distinguish storefront calls from admin/API calls before hiding sold-out items.'
);

fwrite(STDOUT, "PASS: catalog paid-search and CRO contract tests.\n");

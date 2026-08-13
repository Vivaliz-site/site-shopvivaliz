<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/catalog-runtime.php';

function svcr_selection_assert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$root = dirname(__DIR__);
$fallback = svcr_fallback_products($root);
svcr_selection_assert(count($fallback) >= 20, 'fallback curado precisa continuar util');
svcr_selection_assert(svcr_has_available_product($fallback), 'fallback curado precisa provar produto disponivel');

$healthyCache = [[
    'sku' => 'CACHE-HEALTHY',
    'price' => 109.90,
    'stock' => 3,
]];
$healthySelection = svcr_select_catalog_products($healthyCache, $fallback);
svcr_selection_assert(($healthySelection[0]['sku'] ?? '') === 'CACHE-HEALTHY', 'cache ERP saudavel precisa continuar prioritario');

$zeroStockCache = [[
    'sku' => 'CACHE-ZERO-STOCK',
    'price' => 109.90,
    'stock' => 0,
]];
$degradedSelection = svcr_select_catalog_products($zeroStockCache, $fallback);
svcr_selection_assert(($degradedSelection[0]['sku'] ?? '') !== 'CACHE-ZERO-STOCK', 'cache ERP sem nenhum item disponivel nao pode bloquear o fallback');
svcr_selection_assert(svcr_has_available_product($degradedSelection), 'selecao degradada precisa manter ao menos um produto disponivel');

$unavailableFallback = [[
    'sku' => 'FALLBACK-ZERO-STOCK',
    'price' => 79.90,
    'stock' => 0,
]];
$noFabricationSelection = svcr_select_catalog_products($zeroStockCache, $unavailableFallback);
svcr_selection_assert(($noFabricationSelection[0]['sku'] ?? '') === 'CACHE-ZERO-STOCK', 'sem fallback disponivel o runtime nao pode fabricar estoque nem trocar a fonte autoritativa');
svcr_selection_assert(!svcr_has_available_product($noFabricationSelection), 'cenario sem disponibilidade real deve permanecer indisponivel');

fwrite(STDOUT, "catalog-runtime-fallback-selection: ok\n");
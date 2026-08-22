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
svcr_selection_assert($fallback === [], 'fallback curado historico deve permanecer aposentado sob a regra ERP-only');

$healthyCache = [[
    'sku' => 'CACHE-HEALTHY',
    'price' => 109.90,
    'stock' => 3,
]];
$healthySelection = svcr_select_catalog_products($healthyCache, $fallback);
svcr_selection_assert(($healthySelection[0]['sku'] ?? '') === 'CACHE-HEALTHY', 'fonte canonica saudavel precisa continuar prioritaria');

$zeroStockCache = [[
    'sku' => 'CACHE-ZERO-STOCK',
    'price' => 109.90,
    'stock' => 0,
]];
$zeroStockSelection = svcr_select_catalog_products($zeroStockCache, $fallback);
svcr_selection_assert(($zeroStockSelection[0]['sku'] ?? '') === 'CACHE-ZERO-STOCK', 'fallback historico nao pode substituir a fonte canonica por causa de estoque');
svcr_selection_assert(!svcr_has_available_product($zeroStockSelection), 'runtime nao pode fabricar disponibilidade usando snapshot antigo');

$noCanonicalSelection = svcr_select_catalog_products([], $fallback);
svcr_selection_assert($noCanonicalSelection === [], 'sem fonte canonica o catalogo deve falhar fechado em vez de ressuscitar fallback historico');

svcr_selection_assert(svcr_is_active_product(['situacao' => 'A']) === true, 'situacao A deve ser ativa');
svcr_selection_assert(svcr_is_active_product(['status' => 'ACTIVE']) === true, 'status ACTIVE deve ser ativo');
svcr_selection_assert(svcr_is_active_product(['active' => 1]) === true, 'active=1 deve ser ativo');
svcr_selection_assert(svcr_is_active_product(['active' => 0]) === false, 'active=0 deve ser rejeitado');
svcr_selection_assert(svcr_is_active_product(['is_published' => false]) === false, 'nao publicado deve ser rejeitado');
svcr_selection_assert(svcr_is_active_product(['situacao' => 'I']) === false, 'situacao I deve ser rejeitada');
svcr_selection_assert(svcr_is_active_product(['status' => 'INATIVO']) === false, 'status INATIVO deve ser rejeitado');
svcr_selection_assert(svcr_is_active_product(['status' => 'DELETED']) === false, 'status DELETED deve ser rejeitado');
svcr_selection_assert(svcr_is_active_product(['deleted' => true]) === false, 'deleted=true deve ser rejeitado');
svcr_selection_assert(svcr_is_active_product(['archived' => '1']) === false, 'archived=1 deve ser rejeitado');
svcr_selection_assert(svcr_is_active_product(['deleted_at' => '2026-08-16 10:00:00']) === false, 'deleted_at deve ser rejeitado');

fwrite(STDOUT, "catalog-runtime-active-only-selection: ok\n");

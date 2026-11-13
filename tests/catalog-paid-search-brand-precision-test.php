<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/catalog-search-context.php';

// Exercise the same storefront predicate without loading sessions or producing
// a page. The API predicate is already strict; the storefront must agree.
$source = (string) file_get_contents(dirname(__DIR__) . '/catalogo.php');
$start = strpos($source, 'function sv_catalog_search_normalize(');
$end = strpos($source, 'function sv_catalog_load(');
if ($start === false || $end === false || $end <= $start) {
    fwrite(STDERR, "FAIL: storefront predicate boundary missing\n");
    exit(1);
}
eval(substr($source, $start, $end - $start));

function assertRelevant(bool $actual, string $label): void
{
    if (!$actual) {
        fwrite(STDERR, "FAIL: {$label}\n");
        exit(1);
    }
}

$carrinhoFercar = ['sku' => 'C06TPT', 'name' => 'Carrinho Fechado Ferramentas C06 3 Gavetas Fercar'];
$comedouroFercar = ['sku' => 'COM1', 'name' => 'Comedouro Automático Fercar'];
$ferramentaOutraMarca = ['sku' => 'CSD830FBA', 'name' => 'Chave Liga Desliga Margirius', 'category' => 'Ferramentas Manuais'];
$carrinhoOutraMarca = ['sku' => 'CART-X', 'name' => 'Carrinho de Ferramentas de Outra Marca'];
$vedante = ['sku' => 'RODOVED100', 'name' => 'Rodo Vedante Porta Alumínio 100cm'];

assertRelevant(sv_catalog_matches_query($carrinhoFercar, 'carrinho fercar'), 'Real Fercar tool cart must match brand-and-intent query');
assertRelevant(sv_catalog_matches_query($carrinhoFercar, 'carrinhos fercar'), 'Plural cart search must match');
assertRelevant(sv_catalog_matches_query($carrinhoFercar, 'carrinho de ferramentas fercar'), 'Connective words must not break brand-and-intent query');
assertRelevant(!sv_catalog_matches_query($comedouroFercar, 'carrinho fercar'), 'Fercar pet feeder must not match tool cart query');
assertRelevant(!sv_catalog_matches_query($ferramentaOutraMarca, 'carrinho fercar'), 'Generic unrelated tool must not match branded cart query');
assertRelevant(!sv_catalog_matches_query($carrinhoOutraMarca, 'carrinho fercar'), 'Other-brand cart must not match Fercar query');
assertRelevant(!sv_catalog_matches_query($ferramentaOutraMarca, 'fercar'), 'Brand-only search must not match generic tools');
assertRelevant(sv_catalog_matches_query($vedante, 'rodo para porta'), 'Door seal intent must remain relevant');
fwrite(STDOUT, "PASS: paid-search storefront brand-and-intent precision.\n");

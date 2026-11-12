<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/catalog-search-context.php';

function sv_test_assert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$vedante = sv_catalog_search_context('vedante');
sv_test_assert(($vedante['eyebrow'] ?? '') === 'Vedantes para porta', 'vedante eyebrow must match paid-search intent');
sv_test_assert(($vedante['heading'] ?? '') === 'Vedantes para porta em alumínio e borracha', 'vedante heading must match keyword and product');
sv_test_assert(str_contains((string)($vedante['lead'] ?? ''), 'frete por CEP'), 'vedante lead must surface commercial shipping intent');

// Termos usados em buscas comerciais devem levar ao catálogo de vedação,
// sem transformar o nome do produto ou prometer estoque/frete inexistentes.
foreach (['rodo para porta', 'vedador de porta', 'veda porta'] as $searchTerm) {
    $context = sv_catalog_search_context($searchTerm);
    sv_test_assert(($context['eyebrow'] ?? '') === 'Vedantes para porta', $searchTerm . ' eyebrow must match door-seal intent');
    sv_test_assert(($context['heading'] ?? '') === 'Vedantes para porta em alumínio e borracha', $searchTerm . ' must show relevant heading');
}

sv_test_assert(
    sv_catalog_search_terms('RODO PARA PORTA') === ['RODO', 'PORTA'],
    'connector PARA must not hide rodo-para-porta inventory'
);
sv_test_assert(
    sv_catalog_search_terms('VEDADOR DE PORTA') === ['VEDADOR', 'PORTA'],
    'connector DE must not restrict vedador-de-porta inventory'
);
sv_test_assert(
    sv_catalog_search_terms('VEDANTE') === ['VEDANTE'],
    'single term search must stay intact'
);
sv_test_assert(
    sv_catalog_search_terms('PARA') === ['PARA'],
    'standalone connector must not match the entire catalog'
);
sv_test_assert(
    sv_catalog_search_terms('  RODO   PARA  PORTA ') === ['RODO', 'PORTA'],
    'extra whitespace must not hide relevant products'
);

$antique = sv_catalog_search_context('vaso antique');
sv_test_assert(($antique['heading'] ?? '') === 'Vasos Antique Japi', 'Antique search must get a dedicated heading');

$decore = sv_catalog_search_context('vaso decore japi');
sv_test_assert(($decore['heading'] ?? '') === 'Vasos Decore Japi', 'Decore search must get a dedicated heading');

$generic = sv_catalog_search_context('rodizio');
sv_test_assert(($generic['eyebrow'] ?? '') === 'Busca no catálogo', 'generic query must keep neutral search eyebrow');
sv_test_assert(($generic['heading'] ?? '') === 'Resultados para: rodizio', 'generic query must reflect its search term');

$empty = sv_catalog_search_context('');
sv_test_assert(($empty['heading'] ?? '') === 'Produtos Vivaliz', 'empty query must preserve generic catalog heading');

// Paid-search visitors should have an immediately visible route to the
// real product cards rather than scrolling past the complete hero and filters.
$catalogTemplate = (string)file_get_contents(dirname(__DIR__) . '/catalogo.php');
sv_test_assert(
    str_contains($catalogTemplate, 'catalog-search-landing')
      && str_contains($catalogTemplate, 'class="catalog-query-jump"')
      && str_contains($catalogTemplate, 'href="#product-grid"'),
    'Queried catalog must have a direct skip-to-products action.'
);
sv_test_assert(
    str_contains($catalogTemplate, "\$query !== '' && \$products"),
    'Product jump action must not appear on empty or zero-result queries.'
);
$compactCss = dirname(__DIR__) . '/css/catalog-search-compact-v1.css';
sv_test_assert(
    is_file($compactCss)
      && str_contains((string)file_get_contents($compactCss), '.catalog-search-landing .catalog-header'),
    'Search landing must use a scoped compact layout without changing the homepage.'
);

fwrite(STDOUT, "PASS: catalog paid-search landing context.\n");

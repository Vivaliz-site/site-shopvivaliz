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

$antique = sv_catalog_search_context('vaso antique');
sv_test_assert(($antique['heading'] ?? '') === 'Vasos Antique Japi', 'Antique search must get a dedicated heading');

$decore = sv_catalog_search_context('vaso decore japi');
sv_test_assert(($decore['heading'] ?? '') === 'Vasos Decore Japi', 'Decore search must get a dedicated heading');

$generic = sv_catalog_search_context('rodizio');
sv_test_assert(($generic['eyebrow'] ?? '') === 'Busca no catálogo', 'generic query must keep neutral search eyebrow');
sv_test_assert(($generic['heading'] ?? '') === 'Resultados para: rodizio', 'generic query must reflect its search term');

$empty = sv_catalog_search_context('');
sv_test_assert(($empty['heading'] ?? '') === 'Produtos Vivaliz', 'empty query must preserve generic catalog heading');

fwrite(STDOUT, "PASS: catalog paid-search landing context.\n");

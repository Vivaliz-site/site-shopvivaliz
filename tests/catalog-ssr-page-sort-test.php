<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/catalog-page-navigation.php';

function check_catalog(bool $ok, string $message): void {
    if (!$ok) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

check_catalog(sv_catalog_resolve_page(['page' => '2'], 6) === 2, 'Direct page=2 link must render page 2.');
check_catalog(sv_catalog_resolve_page(['pagina' => '3'], 6) === 3, 'Legacy pagina links remain valid.');
check_catalog(sv_catalog_resolve_page(['page' => '99'], 6) === 6, 'Page must clamp to the final page.');
check_catalog(sv_catalog_resolve_page(['page' => ['2']], 6) === 1, 'Array page inputs must not break rendering.');
check_catalog(sv_catalog_resolve_sort(['ordem' => 'price-asc']) === 'price-asc', 'Price sort must survive direct links.');
check_catalog(sv_catalog_resolve_sort(['ordem' => ['name']]) === 'relevance', 'Array sort inputs must fall back safely.');

$url = sv_catalog_page_url(2, 'vedante para porta', 'Ferramentas', 'price-asc');
parse_str((string)parse_url($url, PHP_URL_QUERY), $query);
check_catalog(($query['page'] ?? null) === '2', 'SSR pagination must use page, as the JS and API do.');
check_catalog(($query['ordem'] ?? null) === 'price-asc', 'Pagination must retain the chosen sort.');
check_catalog(($query['q'] ?? null) === 'vedante para porta', 'Pagination must retain the search.');
check_catalog(($query['categoria'] ?? null) === 'Ferramentas', 'Pagination must retain the category.');

$rows = [
    ['name' => 'Zebra', 'price' => 23, 'stock' => 1],
    ['name' => 'Alfa', 'price' => 12, 'stock' => 3],
    ['name' => 'Beta', 'price' => 18, 'stock' => 2],
];
check_catalog(array_column(sv_catalog_sort_rows($rows, 'price-asc'), 'name') === ['Alfa', 'Beta', 'Zebra'], 'Price ascending sorting must occur before SSR pagination.');
check_catalog(array_column(sv_catalog_sort_rows($rows, 'price-desc'), 'name') === ['Zebra', 'Beta', 'Alfa'], 'Price descending sorting must occur before SSR pagination.');
check_catalog(array_column(sv_catalog_sort_rows($rows, 'name'), 'name') === ['Alfa', 'Beta', 'Zebra'], 'Name ordering must be rendered by the backend.');
check_catalog(sv_catalog_sort_rows($rows, 'relevance') === $rows, 'Relevance ranking must be preserved.');

$template = (string)file_get_contents(dirname(__DIR__) . '/catalogo.php');
$client = (string)file_get_contents(dirname(__DIR__) . '/js/catalog.js');
check_catalog(str_contains($template, 'sv_catalog_resolve_page($_GET, $totalPages)'), 'SSR must parse page consistently.');
check_catalog(str_contains($template, 'sv_catalog_products($perPage, $query, $category, $offset, $initialSort)'), 'SSR must apply selected sorting.');
check_catalog(str_contains($template, 'sv_catalog_page_url($currentPage + 1, $query, $category, $initialSort)'), 'Pager must preserve sorting.');
check_catalog(str_contains($template, 'sv-catalog-empty'), 'SSR must expose a recoverable empty result.');
check_catalog(str_contains($client, "params.get('pagina')"), 'JS should support legacy direct links.');
check_catalog(str_contains($client, 'catalogEmptyMarkup'), 'Dynamic searches must offer the same empty recovery.');
echo "PASS: catalog SSR pagination, sort and empty-state parity\n";

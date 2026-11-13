<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/catalog-page-navigation.php';

function assert_case(bool $pass, string $label): void {
    if (!$pass) {
        fwrite(STDERR, "FAIL: {$label}\n");
        exit(1);
    }
    echo "PASS: {$label}\n";
}

$legacy = [
    'busca' => 'vedante', 'categoria' => 'Portas',
    'ordem' => 'price-asc', 'page' => '2',
    'utm_source' => 'google', 'utm_medium' => 'cpc',
    'utm_campaign' => 'auditoria3436', 'utm_term' => 'vedante porta',
    'utm_content' => 'anuncio_a', 'gclid' => 'click_123',
    'gbraid' => 'braid_456', 'wbraid' => 'wbraid_789',
];
assert_case(function_exists('sv_catalog_legacy_search_redirect'), 'legacy canonical helper exists');
$source = file_get_contents(__DIR__ . '/../catalogo.php');
assert_case(is_string($source) && str_contains($source, 'sv_catalog_legacy_search_redirect($_GET)'), 'catalog entrypoint uses helper');

$url = sv_catalog_legacy_search_redirect($legacy);
assert_case(is_string($url) && str_starts_with($url, '/catalogo/?'), 'internal canonical URL');
parse_str((string)parse_url($url, PHP_URL_QUERY), $params);
assert_case(($params['q'] ?? '') === 'vedante', 'canonical query');
assert_case(($params['categoria'] ?? '') === 'Portas', 'category preserved');
assert_case(($params['ordem'] ?? '') === 'price-asc', 'sort preserved');
assert_case(($params['page'] ?? 0) == 2, 'page preserved');
foreach (['utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content', 'gclid', 'gbraid', 'wbraid'] as $key) {
    assert_case(($params[$key] ?? '') === $legacy[$key], "attribution {$key} preserved");
}
assert_case(!isset($params['busca']), 'legacy alias removed');
assert_case(sv_catalog_legacy_search_redirect(['busca' => 'rodo', 'next' => 'https://other.test', 'fbclid' => 'unused']) === '/catalogo/?q=rodo', 'unrelated parameters not forwarded');
$legacyPage = sv_catalog_legacy_search_redirect(['busca' => 'rodo', 'pagina' => '3', 'sort' => 'name']);
parse_str((string)parse_url((string)$legacyPage, PHP_URL_QUERY), $legacyParams);
assert_case(($legacyParams['page'] ?? 0) == 3 && ($legacyParams['ordem'] ?? '') === 'name', 'old page/sort aliases preserved');
assert_case(sv_catalog_legacy_search_redirect(['busca' => 'rodo', 'q' => 'vedante']) === null, 'canonical q not redirected');
assert_case(sv_catalog_legacy_search_redirect(['busca' => '   ']) === null, 'empty search ignored');
assert_case(sv_catalog_legacy_search_redirect(['busca' => ['array']]) === null, 'non scalar search ignored');
echo "Legacy attribution redirect regression: GREEN\n";

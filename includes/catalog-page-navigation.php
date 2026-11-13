<?php
declare(strict_types=1);

/** A URL do catálogo usa page, assim como a API e o histórico do navegador. */
function sv_catalog_resolve_page(array $params, int $totalPages): int
{
    $raw = $params['page'] ?? $params['pagina'] ?? 1;
    $page = is_scalar($raw) ? (int)$raw : 1;
    return max(1, min(max(1, $totalPages), $page));
}

function sv_catalog_resolve_sort(array $params): string
{
    $raw = $params['ordem'] ?? $params['sort'] ?? 'relevance';
    $sort = is_scalar($raw) ? trim((string)$raw) : 'relevance';
    return in_array($sort, ['relevance', 'price-asc', 'price-desc', 'name'], true)
        ? $sort : 'relevance';
}

/** Mantem busca, categoria e ordenacao ao trocar de pagina, inclusive sem JS. */
function sv_catalog_page_url(int $page, string $query, string $category, string $sort = 'relevance'): string
{
    $params = [];
    if ($query !== '') $params['q'] = $query;
    if ($category !== '') $params['categoria'] = $category;
    $sort = sv_catalog_resolve_sort(['ordem' => $sort]);
    if ($sort !== 'relevance') $params['ordem'] = $sort;
    if ($page > 1) $params['page'] = $page;
    $qs = http_build_query($params);
    return '/catalogo/' . ($qs !== '' ? '?' . $qs : '');
}

/** Aplica a ordenacao antes do recorte de pagina, preservando o ranking original por relevancia. */
function sv_catalog_sort_rows(array $rows, string $sort): array
{
    if (!in_array($sort, ['price-asc', 'price-desc', 'name'], true)) return $rows;
    usort($rows, static function (array $a, array $b) use ($sort): int {
        if ($sort === 'name') {
            $nameCmp = strcasecmp((string)($a['name'] ?? ''), (string)($b['name'] ?? ''));
            if ($nameCmp !== 0) return $nameCmp;
        } else {
            $aPrice = (float)($a['price'] ?? 0);
            $bPrice = (float)($b['price'] ?? 0);
            if ($aPrice !== $bPrice) {
                return $sort === 'price-asc' ? $aPrice <=> $bPrice : $bPrice <=> $aPrice;
            }
        }
        $stockCmp = (int)($b['stock'] ?? 0) <=> (int)($a['stock'] ?? 0);
        return $stockCmp !== 0 ? $stockCmp : strcasecmp((string)($a['name'] ?? ''), (string)($b['name'] ?? ''));
    });
    return $rows;
}

/** Preserva atribuicao e estado de navegacao ao canonizar ?busca= para ?q=. */
function sv_catalog_legacy_search_redirect(array $params): ?string
{
    $legacy = $params['busca'] ?? null;
    $canonical = $params['q'] ?? null;
    if (!is_scalar($legacy) || (is_scalar($canonical) && trim((string)$canonical) !== '')) {
        return null;
    }

    $query = trim((string)$legacy);
    if ($query === '') return null;
    $redirect = ['q' => $query];
    $category = $params['categoria'] ?? null;
    if (is_scalar($category) && trim((string)$category) !== '') {
        $redirect['categoria'] = trim((string)$category);
    }
    $sort = sv_catalog_resolve_sort($params);
    if ($sort !== 'relevance') $redirect['ordem'] = $sort;
    $page = $params['page'] ?? $params['pagina'] ?? null;
    if (is_scalar($page) && (int)$page > 1) {
        $redirect['page'] = (int)$page;
    }

    // Apenas parametros de mensuracao conhecidos. Nunca transferir dados
    // arbitrarios do URL para a pagina/analytics ou criar identificadores.
    foreach ([
        'gclid', 'gbraid', 'wbraid', 'dclid',
        'utm_source', 'utm_medium', 'utm_campaign', 'utm_content',
        'utm_term', 'utm_id',
    ] as $key) {
        $value = $params[$key] ?? null;
        if (is_scalar($value) && trim((string)$value) !== '') {
            $redirect[$key] = trim((string)$value);
        }
    }

    return '/catalogo/?' . http_build_query($redirect, '', '&', PHP_QUERY_RFC3986);
}

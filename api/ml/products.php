<?php
declare(strict_types=1);
/**
 * GET /api/ml/products
 * Retorna produtos do catálogo formatados para anúncio no Mercado Livre.
 *
 * Parâmetros opcionais:
 *   ?limit=50        (padrão 50, máx 197)
 *   ?offset=0
 *   ?category=       filtra por categoria interna
 *   ?min_score=0     filtra por quality_score mínimo
 */

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');
header('Access-Control-Allow-Origin: *');

$catalogPath = dirname(__DIR__) . '/catalog/fallback-products.json';
if (!is_file($catalogPath)) {
    http_response_code(503);
    echo json_encode(['ok' => false, 'error' => 'catalog_not_found']);
    exit;
}

$raw = file_get_contents($catalogPath);
$products = $raw ? json_decode($raw, true) : null;
if (!is_array($products)) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'catalog_parse_error']);
    exit;
}

// Filtros
$limit     = max(1, min(197, (int)($_GET['limit'] ?? 50)));
$offset    = max(0, (int)($_GET['offset'] ?? 0));
$catFilter = trim($_GET['category'] ?? '');
$minScore  = (int)($_GET['min_score'] ?? 0);

// Mapeamento de categorias internas → categoria ML (Brasil)
$ML_CATEGORIES = [
    'Rodízios'       => 'MLB5672',   // Rodízios e Roldanas
    'Parafusos'      => 'MLB39381',  // Parafusos
    'Porcas'         => 'MLB39381',
    'Arruelas'       => 'MLB39381',
    'Ferramentas'    => 'MLB1500',   // Ferramentas
    'default'        => 'MLB1574',   // Peças e Ferramentas > Acessórios
];

function suggest_ml_category(string $cat, array $map): string {
    foreach ($map as $key => $mlId) {
        if (stripos($cat, $key) !== false) return $mlId;
    }
    return $map['default'];
}

function format_for_ml(array $p, array $catMap): array {
    $title    = trim($p['name'] ?? '');
    $price    = (float)($p['price'] ?? 0);
    $sku      = (string)($p['sku'] ?? '');
    $category = (string)($p['category'] ?? '');
    $images   = $p['images'] ?? (isset($p['image_url']) ? [$p['image_url']] : []);
    $tags     = $p['tags']   ?? [];

    // Limita título a 60 chars (limite ML)
    $mlTitle = mb_substr($title, 0, 60);

    // Preço mínimo obrigatório para ML: R$5
    $mlPrice = $price > 0 ? round($price, 2) : null;

    return [
        'catalog_id'         => (string)($p['id'] ?? $sku),
        'sku'                => $sku,
        'title'              => $mlTitle,
        'original_title'     => $title,
        'price'              => $mlPrice,
        'currency_id'        => 'BRL',
        'available_quantity' => max(0, (int)($p['stock'] ?? 0)),
        'buying_mode'        => 'buy_it_now',
        'listing_type_id'    => 'gold_special',
        'condition'          => 'new',
        'category_id'        => suggest_ml_category($category, $catMap),
        'category_name'      => $category,
        'pictures'           => array_map(fn($url) => ['source' => $url], array_slice($images, 0, 12)),
        'tags'               => $tags,
        'quality_score'      => (int)($p['quality_score'] ?? 0),
        'quality_label'      => (string)($p['quality_label'] ?? ''),
        'ready_to_publish'   => $mlPrice !== null && $mlPrice >= 5 && count($images) > 0,
    ];
}

// Aplica filtros e formata
$filtered = [];
foreach ($products as $p) {
    if ($catFilter !== '' && stripos((string)($p['category'] ?? ''), $catFilter) === false) continue;
    if ((int)($p['quality_score'] ?? 0) < $minScore) continue;
    $filtered[] = format_for_ml($p, $ML_CATEGORIES);
}

$total = count($filtered);
$page  = array_slice($filtered, $offset, $limit);

echo json_encode([
    'ok'      => true,
    'total'   => $total,
    'offset'  => $offset,
    'limit'   => $limit,
    'items'   => $page,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);

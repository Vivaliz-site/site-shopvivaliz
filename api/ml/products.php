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

if (($_GET['debug_php'] ?? '') === '1') {
    echo json_encode(['php_version' => PHP_VERSION, 'ok' => true]);
    exit;
}
register_shutdown_function(function () {
    $err = error_get_last();
    if ($err && ($_GET['debug_errors'] ?? '') === '1') {
        echo json_encode(['fatal' => $err['message'], 'file' => basename($err['file']), 'line' => $err['line']], JSON_UNESCAPED_UNICODE);
    }
});

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

/** mbstring nem sempre esta disponivel neste hosting; evita fatal error. */
function ml_safe_substr(string $value, int $length): string {
    if (function_exists('mb_substr')) {
        return mb_substr($value, 0, $length);
    }
    return strlen($value) <= $length ? $value : substr($value, 0, $length);
}

function format_for_ml(array $p, array $catMap): array {
    $title    = trim($p['name'] ?? '');
    $price    = (float)($p['price'] ?? 0);
    $sku      = (string)($p['sku'] ?? '');
    $category = (string)($p['category'] ?? '');
    $images   = $p['images'] ?? (isset($p['image_url']) ? [$p['image_url']] : []);
    $tags     = $p['tags']   ?? [];

    // Limita título a 60 chars (limite ML)
    $mlTitle = ml_safe_substr($title, 60);

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

function ml_item_map_path(): string {
    $dir = dirname(__DIR__, 2) . '/storage/private';
    if (!is_dir($dir)) @mkdir($dir, 0750, true);
    return $dir . '/ml-item-map.json';
}

function ml_item_map_read(): array {
    $path = ml_item_map_path();
    if (!is_file($path)) return [];
    $data = json_decode((string)file_get_contents($path), true);
    return is_array($data) ? $data : [];
}

function ml_item_map_write(array $map): void {
    file_put_contents(ml_item_map_path(), json_encode($map, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);
}

/** Remove campos que so existem para uso interno do site, mantendo so o que a API do ML espera. */
function ml_publish_payload(array $formatted): array {
    return [
        'title'              => $formatted['title'],
        'category_id'        => $formatted['category_id'],
        'price'              => $formatted['price'],
        'currency_id'        => $formatted['currency_id'],
        'available_quantity' => $formatted['available_quantity'],
        'buying_mode'        => $formatted['buying_mode'],
        'listing_type_id'    => $formatted['listing_type_id'],
        'condition'          => $formatted['condition'],
        'pictures'           => $formatted['pictures'],
    ];
}

function ml_publish_update_payload(array $formatted): array {
    // Atualizacao: ML nao permite trocar category_id/variations livremente,
    // manda so os campos seguros de reenviar.
    return [
        'title'              => $formatted['title'],
        'price'              => $formatted['price'],
        'available_quantity' => $formatted['available_quantity'],
        'pictures'           => $formatted['pictures'],
    ];
}

function ml_publish_one(array $product, array $catMap): array {
    $formatted = format_for_ml($product, $catMap);
    $sku = $formatted['sku'];

    if (!$formatted['ready_to_publish']) {
        return ['sku' => $sku, 'ok' => false, 'error' => 'not_ready_to_publish', 'formatted' => $formatted];
    }

    $map = ml_item_map_read();
    $existing = $map[$sku] ?? null;

    try {
        if ($existing && !empty($existing['ml_item_id'])) {
            $itemId = $existing['ml_item_id'];
            $resp = ml_http_json('PUT', "https://api.mercadolibre.com/items/{$itemId}", ml_publish_update_payload($formatted));
            $map[$sku] = [
                'ml_item_id'     => $itemId,
                'last_synced_at' => date('c'),
                'last_status'    => $resp['status'] ?? ($existing['last_status'] ?? 'unknown'),
                'action'         => 'update',
            ];
        } else {
            $resp = ml_http_json('POST', 'https://api.mercadolibre.com/items', ml_publish_payload($formatted));
            $itemId = $resp['id'] ?? null;
            if (!$itemId) {
                return ['sku' => $sku, 'ok' => false, 'error' => 'no_item_id_returned', 'response' => $resp];
            }
            $map[$sku] = [
                'ml_item_id'     => $itemId,
                'last_synced_at' => date('c'),
                'last_status'    => $resp['status'] ?? 'unknown',
                'action'         => 'create',
            ];
        }
        ml_item_map_write($map);
        return ['sku' => $sku, 'ok' => true, 'ml_item_id' => $map[$sku]['ml_item_id'], 'action' => $map[$sku]['action'], 'status' => $map[$sku]['last_status']];
    } catch (Throwable $e) {
        return ['sku' => $sku, 'ok' => false, 'error' => 'ml_api_error', 'message' => $e->getMessage()];
    }
}

function ml_publish_log(array $entry): void {
    $dir = dirname(__DIR__, 2) . '/logs';
    if (!is_dir($dir)) @mkdir($dir, 0750, true);
    $entry['at'] = date('c');
    file_put_contents($dir . '/ml-publish.log', json_encode($entry, JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND | LOCK_EX);
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

// POST: publica/atualiza produtos reais na API do ML (cria ou atualiza).
// GET com action=publish_preview: mostra o payload sem chamar a API (nao exige token).
if ($method === 'POST' || ($_GET['action'] ?? '') === 'publish_preview') {
    $isPreview = ($_GET['action'] ?? '') === 'publish_preview';
    $body = json_decode((string)file_get_contents('php://input'), true) ?: [];
    $skus = [];
    if (!empty($body['sku'])) $skus[] = (string)$body['sku'];
    if (!empty($body['skus']) && is_array($body['skus'])) $skus = array_merge($skus, array_map('strval', $body['skus']));
    if ($isPreview && !empty($_GET['sku'])) $skus[] = (string)$_GET['sku'];
    $skus = array_values(array_unique(array_filter($skus)));

    if ($skus === []) {
        http_response_code(422);
        echo json_encode(['ok' => false, 'error' => 'missing_sku', 'message' => 'Informe sku ou skus[] no corpo (POST) ou ?sku= (preview).']);
        exit;
    }

    $bySku = [];
    foreach ($products as $p) {
        $bySku[(string)($p['sku'] ?? '')] = $p;
    }

    if ($isPreview) {
        $out = [];
        foreach ($skus as $sku) {
            if (!isset($bySku[$sku])) { $out[] = ['sku' => $sku, 'ok' => false, 'error' => 'sku_not_found']; continue; }
            $formatted = format_for_ml($bySku[$sku], $ML_CATEGORIES);
            $out[] = ['sku' => $sku, 'ok' => true, 'preview_payload' => ml_publish_payload($formatted), 'ready_to_publish' => $formatted['ready_to_publish']];
        }
        echo json_encode(['ok' => true, 'preview' => true, 'items' => $out], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
        exit;
    }

    require_once __DIR__ . '/client.php';
    $results = [];
    foreach ($skus as $sku) {
        if (!isset($bySku[$sku])) { $results[] = ['sku' => $sku, 'ok' => false, 'error' => 'sku_not_found']; continue; }
        $result = ml_publish_one($bySku[$sku], $ML_CATEGORIES);
        ml_publish_log($result);
        $results[] = $result;
    }

    $anyFail = (bool)array_filter($results, fn($r) => !$r['ok']);
    http_response_code($anyFail ? 207 : 200);
    echo json_encode(['ok' => !$anyFail, 'results' => $results], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    exit;
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

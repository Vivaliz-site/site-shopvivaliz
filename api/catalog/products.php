<?php
/**
 * Catálogo unificado usado pela loja e pelo admin.
 * Fonte principal: runtime do ERP Olist/Tiny, com fallback curado.
 */
declare(strict_types=1);

header_remove('X-Powered-By');
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: public, max-age=60, stale-while-revalidate=300');

function svcat_search_normalize(string $value): string
{
    static $accents = [
        'á'=>'a','à'=>'a','ã'=>'a','â'=>'a','ä'=>'a','é'=>'e','è'=>'e','ê'=>'e','ë'=>'e',
        'í'=>'i','ì'=>'i','î'=>'i','ï'=>'i','ó'=>'o','ò'=>'o','õ'=>'o','ô'=>'o','ö'=>'o',
        'ú'=>'u','ù'=>'u','û'=>'u','ü'=>'u','ç'=>'c','ñ'=>'n','ý'=>'y',
        'Á'=>'A','À'=>'A','Ã'=>'A','Â'=>'A','Ä'=>'A','É'=>'E','È'=>'E','Ê'=>'E','Ë'=>'E',
        'Í'=>'I','Ì'=>'I','Î'=>'I','Ï'=>'I','Ó'=>'O','Õ'=>'O','Ô'=>'O','Ö'=>'O',
        'Ú'=>'U','Ù'=>'U','Û'=>'U','Ü'=>'U','Ç'=>'C','Ñ'=>'N','Ý'=>'Y',
    ];
    $value = strtr(trim($value), $accents);
    return function_exists('mb_strtoupper') ? mb_strtoupper($value, 'UTF-8') : strtoupper($value);
}

/** @return list<string> */
function svcat_search_terms(string $query): array
{
    $normalized = svcat_search_normalize($query);
    $parts = preg_split('/[^A-Z0-9]+/', $normalized) ?: [];
    $stopwords = ['A', 'AS', 'O', 'OS', 'E', 'DE', 'DA', 'DAS', 'DO', 'DOS', 'EM', 'PARA', 'POR', 'COM'];
    $terms = [];
    foreach ($parts as $part) {
        $part = trim($part);
        if ($part === '' || strlen($part) < 2 || in_array($part, $stopwords, true)) continue;
        if (!in_array($part, $terms, true)) $terms[] = $part;
    }
    return $terms;
}

function svcat_search_term_matches(string $haystack, string $term): bool
{
    if (str_contains($haystack, $term)) return true;

    // Busca tolerante a plural simples sem transformar marcas em categorias
    // genéricas. Ex.: "carrinhos fercar" encontra "Carrinho ... Fercar".
    if (strlen($term) > 4 && str_ends_with($term, 'S')) {
        $singular = substr($term, 0, -1);
        if ($singular !== '' && str_contains($haystack, $singular)) return true;
    }
    return false;
}

function svcat_product_matches_query(array $product, string $query): bool
{
    $terms = svcat_search_terms($query);
    if ($terms === []) return true;

    $haystack = svcat_search_normalize(implode(' ', [
        $product['sku'] ?? '',
        $product['name'] ?? '',
        $product['category'] ?? '',
        $product['olist_product_id'] ?? '',
        $product['id'] ?? '',
        $product['description'] ?? '',
    ]));

    // AND entre os termos: evita que uma landing "carrinho fercar" vire uma
    // vitrine genérica de ferramentas, mas permite palavras não contíguas.
    foreach ($terms as $term) {
        if (!svcat_search_term_matches($haystack, $term)) return false;
    }
    return true;
}

function svcat_ml_key_normalize(string $value): string
{
    $value = svcat_search_normalize($value);
    return preg_replace('/[^A-Z0-9]+/', '', $value) ?? '';
}

function svcat_root(): string { return dirname(__DIR__, 2); }
function svcat_response_cache_path(string $key): string
{
    $dir = svcat_root() . '/storage/cache/catalog-api';
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    return $dir . '/' . hash('sha256', $key) . '.json';
}

function svcat_cache_signature(): string
{
    $root = svcat_root();
    $paths = [
        $root . '/storage/products-cache-ativos.json',
        $root . '/storage/cache/products-cache-ativos.json',
        $root . '/storage/ml/product-scores.json',
    ];
    $parts = [];
    foreach ($paths as $path) {
        $parts[] = $path . ':' . (is_file($path) ? ((string)filemtime($path) . ':' . (string)filesize($path)) : 'missing');
    }
    return implode('|', $parts);
}

function svcat_try_emit_cached(string $cachePath, int $ttl = 60): void
{
    if (!is_file($cachePath) || (time() - (int)filemtime($cachePath)) > $ttl) {
        return;
    }
    $body = file_get_contents($cachePath);
    if (!is_string($body) || $body === '') {
        return;
    }
    header('X-ShopVivaliz-Cache: HIT');
    echo $body;
    exit;
}

function svcat_store_response_cache(string $cachePath, array $payload): void
{
    $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    if (!is_string($json)) {
        return;
    }
    @file_put_contents($cachePath, $json, LOCK_EX);
    header('X-ShopVivaliz-Cache: MISS');
    echo $json;
    exit;
}

function svcat_ml_scores(): array
{
    $path = svcat_root() . '/storage/ml/product-scores.json';
    if (!is_file($path)) {
        return [];
    }
    $payload = json_decode((string)file_get_contents($path), true);
    $scores = $payload['scores'] ?? null;
    if (!is_array($scores)) {
        return [];
    }

    $expanded = [];
    foreach ($scores as $key => $score) {
        $rawKey = trim((string)$key);
        if ($rawKey === '') {
            continue;
        }
        $expanded[$rawKey] = (float)$score;
        $normalized = svcat_ml_key_normalize($rawKey);
        if ($normalized !== '') {
            $expanded[$normalized] = (float)$score;
        }
    }
    return $expanded;
}
function svcat_json(int $status, array $payload): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}
function svcat_is_active(mixed $status): bool
{
    $normalized = svcat_search_normalize((string)($status ?? 'ACTIVE'));
    return in_array($normalized, ['', 'A', 'ACTIVE', 'ATIVO', '1'], true);
}

require_once svcat_root() . '/includes/catalog-runtime.php';
require_once svcat_root() . '/includes/catalog-image-enrich.php';

$limit = min(200, max(1, (int)($_GET['limit'] ?? 48)));
$page = max(1, (int)($_GET['page'] ?? 1));
$q = trim((string)($_GET['q'] ?? ''));
$sort = trim((string)($_GET['ordem'] ?? $_GET['sort'] ?? 'relevance'));
$refererPath = (string)(parse_url((string)($_SERVER['HTTP_REFERER'] ?? ''), PHP_URL_PATH) ?: '');
$isStorefrontCatalog = $refererPath === '/catalogo' || str_starts_with($refererPath, '/catalogo/');
$availableOnly = ($_GET['available'] ?? '') === '1'
    || ($isStorefrontCatalog && ($_GET['include_out_of_stock'] ?? '') !== '1');
$cacheKey = http_build_query([
    'limit' => $limit,
    'page' => $page,
    'q' => $q,
    'sort' => $sort,
    'category' => trim((string)($_GET['categoria'] ?? $_GET['category'] ?? '')),
    'available_only' => $availableOnly ? '1' : '0',
    'sig' => svcat_cache_signature(),
]);
$cachePath = svcat_response_cache_path($cacheKey);
if (($_GET['no_cache'] ?? '') !== '1') {
    svcat_try_emit_cached($cachePath);
}
$mlScores = svcat_ml_scores();

$runtimeRows = array_values(array_filter(svcr_products(), 'is_array'));
$allProducts = array_map(static function (array $row): array {
    $images = is_array($row['images'] ?? null) ? array_slice(array_values(array_filter($row['images'])), 0, 10) : [];
    return [
        'id' => (string)($row['id'] ?? $row['sku'] ?? ''),
        'sku' => trim((string)($row['sku'] ?? '')),
        'olist_product_id' => (string)($row['olist_product_id'] ?? $row['id'] ?? ''),
        'name' => trim((string)($row['name'] ?? $row['nome'] ?? 'Produto')),
        'slug' => trim((string)($row['slug'] ?? '')),
        'description' => trim((string)($row['description'] ?? '')),
        'price' => (float)($row['price'] ?? $row['preco'] ?? 0),
        'stock' => (int)($row['stock'] ?? $row['estoque'] ?? 0),
        'image_url' => trim((string)($row['image_url'] ?? $row['image'] ?? '')),
        'images' => $images,
        'images_count' => (int)($row['images_count'] ?? count($images)),
        'category' => trim((string)($row['category'] ?? $row['categoria'] ?? '')),
        'status' => (string)($row['status'] ?? 'active'),
        'ml_score' => null,
    ];
}, $runtimeRows);

// Product registration data is ERP-only. Image enrichment may only use ERP/Tiny
// media tables populated from the product detail endpoint, never local/manual data.
$allProducts = svcie_enrich_images($allProducts);

$allProducts = array_values(array_filter($allProducts, static fn(array $p): bool => svcat_is_active($p['status'] ?? null)));
if ($availableOnly) {
    $allProducts = array_values(array_filter($allProducts, static fn(array $p): bool => (int)($p['stock'] ?? 0) > 0));
}

foreach ($allProducts as &$product) {
    $candidates = array_values(array_unique(array_filter([
        trim((string)($product['sku'] ?? '')),
        trim((string)($product['id'] ?? '')),
        trim((string)($product['olist_product_id'] ?? '')),
    ], static fn(string $value): bool => $value !== '')));

    foreach ($candidates as $candidate) {
        if (array_key_exists($candidate, $mlScores)) {
            $product['ml_score'] = (float)$mlScores[$candidate];
            break;
        }
        $normalized = svcat_ml_key_normalize($candidate);
        if ($normalized !== '' && array_key_exists($normalized, $mlScores)) {
            $product['ml_score'] = (float)$mlScores[$normalized];
            break;
        }
    }
}
unset($product);

if ($q !== '') {
    $allProducts = array_values(array_filter(
        $allProducts,
        static fn(array $product): bool => svcat_product_matches_query($product, $q)
    ));
}

$category = trim((string)($_GET['categoria'] ?? $_GET['category'] ?? ''));
if ($category !== '') {
    $allProducts = array_values(array_filter($allProducts, static fn(array $p): bool => strcasecmp((string)($p['category'] ?? ''), $category) === 0));
}

usort($allProducts, static function (array $a, array $b) use ($sort): int {
    if ($sort === 'price-asc' || $sort === 'price-desc') {
        $priceA = (float)($a['price'] ?? 0);
        $priceB = (float)($b['price'] ?? 0);
        if ($priceA !== $priceB) {
            return $sort === 'price-asc' ? ($priceA <=> $priceB) : ($priceB <=> $priceA);
        }
    } elseif ($sort === 'name') {
        $nameCmp = strcasecmp((string)($a['name'] ?? ''), (string)($b['name'] ?? ''));
        if ($nameCmp !== 0) {
            return $nameCmp;
        }
    } else {
        $scoreA = (float)($a['ml_score'] ?? -INF);
        $scoreB = (float)($b['ml_score'] ?? -INF);
        if ($scoreA !== $scoreB) {
            return $scoreB <=> $scoreA;
        }
    }

    $stockA = (int)($a['stock'] ?? 0);
    $stockB = (int)($b['stock'] ?? 0);
    if ($stockA !== $stockB) {
        return $stockB <=> $stockA;
    }

    return strcasecmp((string)($a['name'] ?? ''), (string)($b['name'] ?? ''));
});

$total = count($allProducts);
$totalPages = max(1, (int)ceil($total / max(1, $limit)));
$page = min($page, $totalPages);
$offset = ($page - 1) * $limit;
$products = array_slice($allProducts, $offset, $limit);
$categories = [];
foreach ($allProducts as $row) {
    $cat = trim((string)($row['category'] ?? ''));
    if ($cat !== '') $categories[$cat] = ($categories[$cat] ?? 0) + 1;
}
arsort($categories);

$payload = [
    'ok' => true,
    'source' => 'catalog_runtime',
    'count' => count($products),
    'total' => $total,
    'page' => $page,
    'limit' => $limit,
    'total_pages' => $totalPages,
    'sort' => $sort !== '' ? $sort : 'relevance',
    'available_only' => $availableOnly,
    'products' => $products,
    'categories' => $categories,
];

svcat_store_response_cache($cachePath, $payload);

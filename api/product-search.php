<?php
/**
 * RESTful Product Search Endpoint
 * Provides product search functionality via GET requests
 * URL: /api/product-search.php?q=search_term&limit=20&page=1&sort=relevance
 */
declare(strict_types=1);

header_remove('X-Powered-By');
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Método não permitido. Use GET.'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function svcat_search_normalize(string $value): string
{
    static $accents = [
        'á'=>'a','à'=>'a','ã'=>'a','â'=>'a','ä'=>'a','é'=>'e','è'=>'e','ê'=>'e','ë'=>'e',
        'í'=>'i','ì'=>'i','î'=>'i','ï'=>'i','ó'=>'o','ò'=>'o','õ'=>'o','ô'=>'o','ö'=>'o',
        'ú'=>'u','ù'=>'u','û'=>'u','ü'=>'u','ç'=>'c','ñ'=>'n','ý'=>'y',
        'Á'=>'A','À'=>'A','Ã'=>'A','Â'=>'A','Ä'=>'A','É'=>'E','È'=>'E','Ê'=>'E','Ë'=>'E',
        'Í'=>'I','Ì'=>'I','Î'=>'I','Ï'=>'I','Ó'=>'O','Ò'=>'O','Õ'=>'O','Ô'=>'O','Ö'=>'O',
        'Ú'=>'U','Ù'=>'U','Û'=>'U','Ü'=>'U','Ç'=>'C','Ñ'=>'N','Ý'=>'Y',
    ];
    $value = strtr(trim($value), $accents);
    return function_exists('mb_strtoupper') ? mb_strtoupper($value, 'UTF-8') : strtoupper($value);
}

function svcat_ml_key_normalize(string $value): string
{
    $value = svcat_search_normalize($value);
    return preg_replace('/[^A-Z0-9]+/', '', $value) ?? '';
}

function svcat_root(): string { return dirname(__DIR__); }

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

function svcat_search_score(array $product, string $query, array $mlScores): float
{
    $score = 0.0;
    $normalizedQuery = svcat_search_normalize($query);
    $queryWords = array_filter(explode(' ', $normalizedQuery));

    $name = svcat_search_normalize($product['name'] ?? '');
    $sku = svcat_search_normalize($product['sku'] ?? '');
    $description = svcat_search_normalize($product['description'] ?? '');
    $category = svcat_search_normalize($product['category'] ?? '');

    foreach ($queryWords as $word) {
        if ($word === '') continue;

        if (str_contains($sku, $word)) {
            $score += 100;
        }
        if (str_contains($name, $word)) {
            $score += 50;
        }
        if (str_contains($category, $word)) {
            $score += 20;
        }
        if (str_contains($description, $word)) {
            $score += 10;
        }
    }

    $mlKey = $product['sku'] ?? '';
    if (isset($mlScores[$mlKey])) {
        $score += $mlScores[$mlKey] * 5;
    }

    return $score;
}

require_once svcat_root() . '/includes/catalog-runtime.php';

$limit = min(100, max(1, (int)($_GET['limit'] ?? 20)));
$page = max(1, (int)($_GET['page'] ?? 1));
$q = trim((string)($_GET['q'] ?? $_GET['busca'] ?? $_GET['query'] ?? ''));
$sort = trim((string)($_GET['sort'] ?? 'relevance'));

if ($q === '') {
    svcat_json(400, ['ok' => false, 'error' => 'Parâmetro de busca "q" é obrigatório.']);
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

$allProducts = array_values(array_filter($allProducts, static fn(array $p): bool => svcat_is_active($p['status'] ?? null)));

$scoredProducts = [];
foreach ($allProducts as $product) {
    $score = svcat_search_score($product, $q, $mlScores);
    if ($score > 0) {
        $product['ml_score'] = $score;
        $scoredProducts[] = $product;
    }
}

usort($scoredProducts, function (array $a, array $b) use ($sort): int {
    if ($sort === 'price_asc') {
        return $a['price'] <=> $b['price'];
    }
    if ($sort === 'price_desc') {
        return $b['price'] <=> $a['price'];
    }
    if ($sort === 'name_asc') {
        return strcmp($a['name'], $b['name']);
    }
    if ($sort === 'name_desc') {
        return strcmp($b['name'], $a['name']);
    }
    return ($b['ml_score'] ?? 0) <=> ($a['ml_score'] ?? 0);
});

$total = count($scoredProducts);
$offset = ($page - 1) * $limit;
$pagedProducts = array_slice($scoredProducts, $offset, $limit);

$response = [
    'ok' => true,
    'data' => [
        'products' => array_values($pagedProducts),
        'pagination' => [
            'page' => $page,
            'limit' => $limit,
            'total' => $total,
            'total_pages' => (int)ceil($total / $limit),
            'has_next' => ($offset + $limit) < $total,
            'has_prev' => $page > 1,
        ],
        'query' => $q,
        'sort' => $sort,
    ],
];

svcat_json(200, $response);

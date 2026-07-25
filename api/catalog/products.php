<?php
/**
 * Catálogo unificado usado pela loja e pelo admin.
 * Fonte principal: runtime do ERP Olist/Tiny, com fallback curado.
 */
declare(strict_types=1);

header_remove('X-Powered-By');
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

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

function svcat_root(): string { return dirname(__DIR__, 2); }
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

$limit = min(200, max(1, (int)($_GET['limit'] ?? 48)));
$q = trim((string)($_GET['q'] ?? ''));

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
    ];
}, $runtimeRows);

$allProducts = array_values(array_filter($allProducts, static fn(array $p): bool => svcat_is_active($p['status'] ?? null)));

if ($q !== '') {
    $needle = svcat_search_normalize($q);
    $allProducts = array_values(array_filter($allProducts, static function (array $p) use ($needle): bool {
        return str_contains(svcat_search_normalize(implode(' ', [
            $p['sku'] ?? '', $p['name'] ?? '', $p['category'] ?? '', $p['olist_product_id'] ?? '',
        ])), $needle);
    }));
}

$category = trim((string)($_GET['categoria'] ?? $_GET['category'] ?? ''));
if ($category !== '') {
    $allProducts = array_values(array_filter($allProducts, static fn(array $p): bool => strcasecmp((string)($p['category'] ?? ''), $category) === 0));
}

$products = array_slice($allProducts, 0, $limit);
$categories = [];
foreach ($allProducts as $row) {
    $cat = trim((string)($row['category'] ?? ''));
    if ($cat !== '') $categories[$cat] = ($categories[$cat] ?? 0) + 1;
}
arsort($categories);

svcat_json(200, [
    'ok' => true,
    'source' => 'catalog_runtime',
    'count' => count($products),
    'total' => count($allProducts),
    'products' => $products,
    'categories' => $categories,
]);

<?php
/**
 * ALTERADO 2026-07-13: Busca DIRETO do ERP OLIST (Tiny)
 * FONTE DE VERDADE: ERP OLIST apenas
 * E-commerce local desativado
 */

declare(strict_types=1);

header_remove('X-Powered-By');
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');

function svcat_search_normalize(string $value): string
{
    static $accents = [
        'á' => 'a', 'à' => 'a', 'ã' => 'a', 'â' => 'a', 'ä' => 'a',
        'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
        'í' => 'i', 'ì' => 'i', 'î' => 'i', 'ï' => 'i',
        'ó' => 'o', 'ò' => 'o', 'õ' => 'o', 'ô' => 'o', 'ö' => 'o',
        'ú' => 'u', 'ù' => 'u', 'û' => 'u', 'ü' => 'u',
        'ç' => 'c', 'ñ' => 'n', 'ý' => 'y',
        'Á' => 'A', 'À' => 'A', 'Ã' => 'A', 'Â' => 'A', 'Ä' => 'A',
        'É' => 'E', 'È' => 'E', 'Ê' => 'E', 'Ë' => 'E',
        'Í' => 'I', 'Ì' => 'I', 'Î' => 'I', 'Ï' => 'I',
        'Ó' => 'O', 'Ò' => 'O', 'Õ' => 'O', 'Ô' => 'O', 'Ö' => 'O',
        'Ú' => 'U', 'Ù' => 'U', 'Û' => 'U', 'Ü' => 'U',
        'Ç' => 'C', 'Ñ' => 'N', 'Ý' => 'Y',
    ];

    $value = trim($value);
    $value = strtr($value, $accents);
    return function_exists('mb_strtoupper') ? mb_strtoupper($value, 'UTF-8') : strtoupper($value);
}

function svcat_root(): string
{
    return dirname(__DIR__, 2);
}

function svcat_json(int $status, array $payload): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

function svcat_env_load(): void
{
    $path = svcat_root() . '/.env';
    if (!is_file($path) || !is_readable($path)) return;
    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) continue;
        [$key, $value] = explode('=', $line, 2);
        $key = trim($key);
        $value = trim(trim($value), "\"'");
        if ($key !== '' && getenv($key) === false) {
            putenv($key . '=' . $value);
        }
    }
}


// ALTERADO 2026-07-17: usa svcr_products() -- a mesma fonte de dados do
// checkout (/api/cart/validate.php) e da pagina de produto (produto.php).
// Antes este endpoint lia o cache/API do ERP direto, uma fonte separada do
// arquivo curado (api/catalog/fallback-products.json) usado no resto do
// site. As duas fontes ficavam dessincronizadas: produtos apareciam
// "disponivel" no catalogo mas "esgotado" na pagina do produto (ou o
// carrinho recusava um item que o catalogo mostrava como comprável).
require_once dirname(__DIR__, 2) . '/includes/catalog-runtime.php';

$limit = min(200, max(1, (int)($_GET['limit'] ?? 48)));
$q = trim((string)($_GET['q'] ?? ''));

$all_erp = array_map(static function (array $row): array {
    return [
        'id' => (string)($row['id'] ?? $row['sku'] ?? ''),
        'sku' => trim((string)($row['sku'] ?? '')),
        'olist_product_id' => (string)($row['olist_product_id'] ?? ''),
        'name' => trim((string)($row['name'] ?? 'Produto')),
        'description' => trim((string)($row['description'] ?? '')),
        'price' => (float)($row['price'] ?? 0),
        'stock' => (int)($row['stock'] ?? 0),
        'image_url' => trim((string)($row['image_url'] ?? '')),
        'images_count' => (int)($row['images_count'] ?? 0),
        'category' => trim((string)($row['category'] ?? '')),
        'status' => 'active',
    ];
}, array_values(array_filter(svcr_products(), 'is_array')));

if ($q !== '') {
    $qNormalized = svcat_search_normalize($q);
    $all_erp = array_filter($all_erp, function($p) use ($qNormalized) {
        $searchText = svcat_search_normalize($p['sku'] . ' ' . $p['name']);
        return strpos($searchText, $qNormalized) !== false;
    });
}

$categoria = trim((string)($_GET['categoria'] ?? $_GET['category'] ?? ''));
if ($categoria !== '') {
    $all_erp = array_filter($all_erp, function ($p) use ($categoria) {
        return strcasecmp((string)($p['category'] ?? ''), $categoria) === 0;
    });
}

$products = array_slice(array_values($all_erp), 0, $limit);

// Categorias do fallback.json (apenas leitura)
$categories = [];
$jsonPath = svcat_root() . '/api/catalog/fallback-products.json';
if (is_file($jsonPath)) {
    $all = json_decode((string)file_get_contents($jsonPath), true) ?: [];
    $catCount = [];
    foreach ($all as $row) {
        $cat = (string)($row['category'] ?? '');
        if ($cat !== '') $catCount[$cat] = ($catCount[$cat] ?? 0) + 1;
    }
    arsort($catCount);
    $categories = $catCount;
}

if (empty($categories)) {
    $catCount = [];
    foreach ($all_erp as $row) {
        $cat = (string)($row['category'] ?? '');
        if ($cat !== '') $catCount[$cat] = ($catCount[$cat] ?? 0) + 1;
    }
    arsort($catCount);
    $categories = $catCount;
}

svcat_json(200, [
    'ok'         => true,
    'source'     => 'erp_olist',
    'count'         => count($products),
    'products'   => $products,
    'categories' => $categories,
]);


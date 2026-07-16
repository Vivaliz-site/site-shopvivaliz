<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$limit = min(200, max(1, (int)($_GET['limit'] ?? 48)));
$q = trim((string)($_GET['q'] ?? ''));

$products = [];
$phpFile = __DIR__ . '/../olist/produtos-olist-array.php';

if (is_file($phpFile) && is_readable($phpFile)) {
    include $phpFile;
    if (!empty($GLOBALS['produtos_olist'])) {
        foreach ($GLOBALS['produtos_olist'] as $p) {
            $sku = $p['id'] ?? '';
            $name = $p['nome'] ?? '';
            $image = $p['url_imagem'] ?? '';

            if ($sku === '' || $image === '') continue;
            if ($q !== '' && stripos($sku . ' ' . $name, $q) === false) continue;

            $products[] = [
                'sku' => $sku,
                'name' => $name,
                'price' => (float)($p['preco'] ?? 0),
                'image_url' => $image,
                'olist_product_id' => $sku,
                'images_count' => 1,
            ];

            if (count($products) >= $limit) break;
        }
    }
}

http_response_code(200);
echo json_encode([
    'ok' => true,
    'source' => 'php_products',
    'count' => count($products),
    'products' => $products,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

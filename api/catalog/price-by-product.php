<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

require_once dirname(__DIR__, 2) . '/includes/catalog-runtime.php';

$sku = trim((string)($_GET['sku'] ?? ''));
$id = trim((string)($_GET['id'] ?? $_GET['olist_product_id'] ?? ''));
if ($sku === '' && $id === '') {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'missing_identifier']);
    exit;
}
$cachePath = dirname(__DIR__, 2) . '/storage/products-cache-ativos.json';
$snapshot = is_file($cachePath) ? json_decode((string)file_get_contents($cachePath), true) : null;
if (!is_array($snapshot)) {
    http_response_code(503);
    echo json_encode(['ok' => false, 'error' => 'catalog_cache_unavailable']);
    exit;
}
if (svcr_is_quarantined_identifier($snapshot, $sku, $id)) {
    http_response_code(409);
    echo json_encode(['ok' => false, 'error' => 'erp_sku_quarantined']);
    exit;
}
$matches = [];
foreach (svcr_products() as $row) {
    if (!is_array($row)) continue;
    $rowSku = trim((string)($row['sku'] ?? ''));
    $rowId = trim((string)($row['olist_product_id'] ?? $row['id'] ?? ''));
    if ($sku !== '' && strcasecmp($sku, $rowSku) !== 0) continue;
    if ($id !== '' && $id !== $rowId) continue;
    $matches[] = $row;
}
if (count($matches) > 1) {
    http_response_code(409);
    echo json_encode(['ok' => false, 'error' => 'ambiguous_erp_identity']);
    exit;
}
if ($matches === []) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'product_not_found_or_not_pdv_verified']);
    exit;
}
$row = $matches[0];
if (($row['price_source'] ?? '') !== 'olist_pdv' || (int)($row['pdv_list_id'] ?? 0) < 1) {
    http_response_code(503);
    echo json_encode(['ok' => false, 'error' => 'pdv_price_not_verified']);
    exit;
}
$regular = (float)($row['regular_price'] ?? 0);
$promo = (float)($row['promotional_price'] ?? 0);
$price = (float)($row['price'] ?? 0);
$stock = (int)($row['stock'] ?? 0);
if ($regular <= 0 || $price <= 0 || ($promo > 0 && $promo >= $regular)) {
    http_response_code(503);
    echo json_encode(['ok' => false, 'error' => 'pdv_price_invalid']);
    exit;
}
echo json_encode([
    'ok' => true,
    'sku' => (string)$row['sku'],
    'olist_product_id' => (string)($row['olist_product_id'] ?? ''),
    'price_source' => 'olist_pdv',
    'pdv_list_id' => (int)$row['pdv_list_id'],
    'price_base' => round($regular, 2),
    'price_promotional' => $promo > 0 ? round($promo, 2) : null,
    'price_valid' => true,
    'price' => round($price, 2),
    'stock' => $stock,
    'purchasable' => $stock > 0,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

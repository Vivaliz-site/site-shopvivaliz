<?php
declare(strict_types=1);

header_remove('X-Powered-By');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

require_once dirname(__DIR__, 2) . '/includes/product-price-enrich.php';
require_once dirname(__DIR__, 2) . '/includes/catalog-runtime.php';

$sku = trim((string)($_GET['sku'] ?? ''));
$id = trim((string)($_GET['id'] ?? $_GET['olist_product_id'] ?? ''));

if ($sku === '' && $id === '') {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'missing_identifier'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$row = [];
$rows = svcr_products();
foreach ($rows as $candidate) {
    if (!is_array($candidate)) {
        continue;
    }

    $rowSku = trim((string)($candidate['sku'] ?? ''));
    $rowId = trim((string)($candidate['olist_product_id'] ?? $candidate['id'] ?? ''));

    if (($sku !== '' && strcasecmp($rowSku, $sku) === 0) || ($id !== '' && $rowId === $id)) {
        $row = $candidate;
        break;
    }
}
if ($row === []) {
    $db = svp_db();
    $row = svp_lookup_product($db, $sku, $id);
    if ($db instanceof mysqli) $db->close();
}

if ($row === []) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'product_not_found'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$stock = max(0, (int)($row['stock'] ?? 0));
$price = (float)($row['price'] ?? 0);

echo json_encode([
    'ok' => true,
    'sku' => (string)($row['sku'] ?? $sku),
    'olist_product_id' => (string)($row['olist_product_id'] ?? $id),
    'stock' => $stock,
    'available' => $stock > 0,
    'purchasable' => $stock > 0 && $price > 0,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

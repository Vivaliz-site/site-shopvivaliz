<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: public, max-age=300');
header('X-Content-Type-Options: nosniff');
require_once dirname(__DIR__, 2) . '/includes/catalog-runtime.php';

$sku = trim((string)($_GET['sku'] ?? ''));
$id = trim((string)($_GET['id'] ?? $_GET['olist_product_id'] ?? ''));
if ($sku === '' && $id === '') {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'missing_identifier']);
    exit;
}

$rows = svcr_products();
foreach (is_array($rows) ? $rows : [] as $row) {
    if (!is_array($row)) continue;
    $rowSku = trim((string)($row['sku'] ?? ''));
    $rowId = trim((string)($row['olist_product_id'] ?? $row['id'] ?? ''));
    if (($sku !== '' && strcasecmp($rowSku, $sku) !== 0) && ($id === '' || $rowId !== $id)) continue;
    $image = trim((string)($row['image_url'] ?? ''));
    $lower = strtolower($image);
    $valid = $image !== '' && !str_contains($lower, 'placeholder') && !str_contains($lower, 'logo-vivaliz');
    echo json_encode([
        'ok' => true,
        'sku' => $rowSku,
        'olist_product_id' => $rowId,
        'valid_image' => $valid,
        'image_url' => $valid ? $image : null,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

http_response_code(404);
echo json_encode(['ok' => false, 'error' => 'product_not_found']);

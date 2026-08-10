<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../includes/admin-guard.php';
require_once __DIR__ . '/../config_optimization.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Use GET.']);
    exit;
}

$channel = strtolower(trim((string)($_GET['channel'] ?? '')));
$limit = max(1, min(100, (int)($_GET['limit'] ?? 30)));
$channels = catalog_ai_channels();
if (!isset($channels[$channel])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Canal invalido.']);
    exit;
}

$db = catalog_ai_db();
if (!$db instanceof PDO) {
    http_response_code(503);
    echo json_encode(['success' => false, 'error' => 'Banco de dados temporariamente indisponivel.']);
    exit;
}

try {
    $stmt = $db->prepare(
        'SELECT p.id, p.name, p.sku, p.image_url, p.description, '
        . 'CASE WHEN COALESCE(NULLIF(TRIM(p.image_url), ""), "") = "" THEN 0 ELSE 1 END AS has_image, '
        . 'CASE WHEN COALESCE(NULLIF(TRIM(p.description), ""), "") = "" THEN 0 ELSE 1 END AS has_description, '
        . 'CASE WHEN COALESCE(NULLIF(TRIM(p.sku), ""), "") = "" THEN 0 ELSE 1 END AS has_sku, '
        . 'CASE WHEN COALESCE(NULLIF(TRIM(p.image_url), ""), "") = "" THEN 0 ELSE 30 END '
        . '+ CASE WHEN COALESCE(NULLIF(TRIM(p.description), ""), "") = "" THEN 0 ELSE 10 END '
        . '+ CASE WHEN COALESCE(NULLIF(TRIM(p.sku), ""), "") = "" THEN 0 ELSE 5 END AS priority_score '
        . 'FROM products p '
        . 'WHERE NOT EXISTS ('
        . ' SELECT 1 FROM catalog_optimizations_staging s '
        . ' WHERE s.product_id = p.id AND s.channel = ? '
        . " AND COALESCE(s.status, '') NOT IN ('published','rejected','failed')"
        . ') '
        . 'ORDER BY priority_score DESC, p.id ASC LIMIT ' . (int)$limit
    );
    $stmt->execute([$channel]);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    echo json_encode([
        'success' => true,
        'channel' => $channel,
        'items' => $items,
        'count' => count($items),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    error_log('[catalog-optimization] pending_candidates_unique: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Nao foi possivel carregar os candidatos.']);
}

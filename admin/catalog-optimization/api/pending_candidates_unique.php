<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../includes/admin-guard.php';
require_once __DIR__ . '/../config_optimization.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('X-Content-Type-Options: nosniff');

function cat_candidate_json(array $payload, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function cat_candidate_active_sql(PDO $db, string $alias = 'p'): string
{
    $prefix = preg_replace('/[^a-zA-Z0-9_]/', '', $alias) ?: 'p';
    $columns = [];
    try {
        $statement = $db->query('SHOW COLUMNS FROM products');
        foreach ($statement ? $statement->fetchAll(PDO::FETCH_ASSOC) : [] as $row) {
            $field = strtolower(trim((string)($row['Field'] ?? '')));
            if ($field !== '') {
                $columns[$field] = true;
            }
        }
    } catch (Throwable) {
        $columns = ['active' => true];
    }

    $parts = [];
    foreach (['active', 'is_active', 'ativo'] as $column) {
        if (isset($columns[$column])) {
            $parts[] = "COALESCE({$prefix}.{$column}, 0) = 1";
        }
    }
    foreach (['situacao', 'status'] as $column) {
        if (isset($columns[$column])) {
            $parts[] = "UPPER(COALESCE({$prefix}.{$column}, 'A')) NOT IN ('I','INATIVO','INACTIVE','DESATIVADO','DISABLED','EXCLUIDO','DELETED')";
        }
    }
    return $parts !== [] ? implode(' AND ', $parts) : '1=1';
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    cat_candidate_json(['success' => false, 'error' => 'Use GET.'], 405);
}

$channel = strtolower(trim((string)($_GET['channel'] ?? '')));
$rawLimit = (int)($_GET['limit'] ?? 30);
$limit = $rawLimit <= 0 ? 5000 : max(1, min(5000, $rawLimit));
$channels = catalog_ai_channels();
if (!isset($channels[$channel])) {
    cat_candidate_json(['success' => false, 'error' => 'Canal invalido.'], 400);
}

$db = catalog_ai_db();
if (!$db instanceof PDO) {
    cat_candidate_json(['success' => false, 'error' => 'Banco de dados temporariamente indisponivel.'], 503);
}

try {
    $activeSql = cat_candidate_active_sql($db, 'p');
    $openDraftSql = 'EXISTS ('
        . ' SELECT 1 FROM catalog_optimizations_staging s'
        . ' WHERE s.product_id = p.id AND s.channel = ?'
        . " AND COALESCE(s.status, '') NOT IN ('published','rejected','failed')"
        . ')';

    $activeStmt = $db->query('SELECT COUNT(*) FROM products p WHERE ' . $activeSql);
    $activeCount = (int)($activeStmt ? $activeStmt->fetchColumn() : 0);

    $inReviewStmt = $db->prepare('SELECT COUNT(*) FROM products p WHERE ' . $activeSql . ' AND ' . $openDraftSql);
    $inReviewStmt->execute([$channel]);
    $inReviewCount = (int)$inReviewStmt->fetchColumn();
    $eligibleCount = max(0, $activeCount - $inReviewCount);

    $stmt = $db->prepare(
        'SELECT p.id, p.name, p.sku, p.image_url, p.description, '
        . 'CASE WHEN COALESCE(NULLIF(TRIM(p.image_url), ""), "") = "" THEN 0 ELSE 1 END AS has_image, '
        . 'CASE WHEN COALESCE(NULLIF(TRIM(p.description), ""), "") = "" THEN 0 ELSE 1 END AS has_description, '
        . 'CASE WHEN COALESCE(NULLIF(TRIM(p.sku), ""), "") = "" THEN 0 ELSE 1 END AS has_sku, '
        . 'CASE WHEN COALESCE(NULLIF(TRIM(p.image_url), ""), "") = "" THEN 0 ELSE 30 END '
        . '+ CASE WHEN COALESCE(NULLIF(TRIM(p.description), ""), "") = "" THEN 0 ELSE 10 END '
        . '+ CASE WHEN COALESCE(NULLIF(TRIM(p.sku), ""), "") = "" THEN 0 ELSE 5 END AS priority_score '
        . 'FROM products p '
        . 'WHERE ' . $activeSql . ' '
        . 'AND NOT ' . $openDraftSql . ' '
        . 'ORDER BY priority_score DESC, p.id ASC LIMIT ' . (int)$limit
    );
    $stmt->execute([$channel]);
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    cat_candidate_json([
        'success' => true,
        'channel' => $channel,
        'items' => $items,
        'count' => count($items),
        'summary' => [
            'active' => $activeCount,
            'eligible' => $eligibleCount,
            'in_review' => $inReviewCount,
            'returned' => count($items),
            'limit' => $limit,
        ],
    ]);
} catch (Throwable $exception) {
    error_log('[catalog-optimization] pending_candidates_unique: ' . $exception->getMessage());
    cat_candidate_json(['success' => false, 'error' => 'Nao foi possivel carregar os candidatos.'], 500);
}

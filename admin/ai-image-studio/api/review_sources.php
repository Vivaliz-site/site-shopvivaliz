<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../includes/admin-guard.php';
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../../../includes/catalog-publication-schema.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Use GET.']);
    exit;
}

$rawIds = $_GET['ids'] ?? '';
$values = is_array($rawIds) ? $rawIds : preg_split('/[,\s]+/', (string)$rawIds, -1, PREG_SPLIT_NO_EMPTY);
$ids = array_values(array_unique(array_filter(array_map('intval', (array)$values), static fn(int $id): bool => $id > 0)));
if ($ids === [] || count($ids) > 100) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Informe entre 1 e 100 staging_ids.']);
    exit;
}

$db = ai_studio_db();
if (!$db instanceof PDO) {
    http_response_code(503);
    echo json_encode(['success' => false, 'error' => 'Banco de dados temporariamente indisponivel.']);
    exit;
}
svcp_ensure_schema($db);

try {
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $db->prepare(
        "SELECT id, product_id, source_job_id, source_image_ref, target_channels_json, status "
        . "FROM product_images_staging WHERE id IN ({$placeholders})"
    );
    $stmt->execute($ids);
    $items = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        $source = trim((string)($row['source_image_ref'] ?? ''));
        if ($source !== '' && preg_match('~^(?:https?://|/)~i', $source) !== 1) {
            $source = '';
        }
        $targets = json_decode((string)($row['target_channels_json'] ?? '[]'), true);
        $channel = is_array($targets) && count($targets) === 1 ? strtolower(trim((string)$targets[0])) : '';
        $items[] = [
            'staging_id' => (int)$row['id'],
            'product_id' => (int)$row['product_id'],
            'source_job_id' => (int)($row['source_job_id'] ?? 0),
            'source_image_ref' => $source,
            'source_auditable' => $source !== '',
            'target_channel' => $channel,
            'status' => strtolower(trim((string)($row['status'] ?? ''))),
        ];
    }
    echo json_encode([
        'success' => true,
        'items' => $items,
        'requested' => count($ids),
        'returned' => count($items),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    error_log('[ai-image-studio] review_sources: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Nao foi possivel validar as referencias visuais.']);
}

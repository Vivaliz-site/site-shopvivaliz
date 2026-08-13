<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/admin-guard.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('X-Content-Type-Options: nosniff');

function sv_ai_summary_response(array $payload, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function sv_ai_summary_table_exists(PDO $db, string $table): bool
{
    $stmt = $db->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?');
    $stmt->execute([$table]);
    return (int)$stmt->fetchColumn() > 0;
}

function sv_ai_summary_counts(PDO $db, string $table, array $groups): array
{
    $result = [];
    foreach ($groups as $name => $_statuses) {
        $result[$name] = 0;
    }
    if (!sv_ai_summary_table_exists($db, $table)) {
        return $result;
    }

    $stmt = $db->query('SELECT LOWER(COALESCE(status, \'\')) AS status, COUNT(*) AS total FROM `' . $table . '` GROUP BY LOWER(COALESCE(status, \'\'))');
    $statusCounts = [];
    foreach ($stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [] as $row) {
        $statusCounts[(string)($row['status'] ?? '')] = (int)($row['total'] ?? 0);
    }
    foreach ($groups as $name => $statuses) {
        foreach ($statuses as $status) {
            $result[$name] += (int)($statusCounts[strtolower((string)$status)] ?? 0);
        }
    }
    return $result;
}

try {
    $db = function_exists('sv_pdo') ? sv_pdo() : null;
    if (!$db instanceof PDO) {
        sv_ai_summary_response(['success' => false, 'error' => 'Banco de dados temporariamente indisponivel.'], 503);
    }

    $images = sv_ai_summary_counts($db, 'product_images_staging', [
        'pending' => ['pending', 'queued', 'processing', 'generating'],
        'ready' => ['generated', 'ready', 'approved', 'published'],
        'failed' => ['failed', 'publication_failed'],
    ]);
    $catalog = sv_ai_summary_counts($db, 'catalog_optimizations_staging', [
        'pending' => ['pending'],
        'ready' => ['submitted', 'published'],
        'failed' => ['failed', 'publication_failed'],
    ]);

    sv_ai_summary_response([
        'success' => true,
        'images' => $images,
        'catalog' => $catalog,
        'checked_at' => gmdate(DATE_ATOM),
    ]);
} catch (Throwable $exception) {
    error_log('[admin-ai-summary] ' . $exception->getMessage());
    sv_ai_summary_response(['success' => false, 'error' => 'Nao foi possivel calcular o resumo das rotinas.'], 500);
}

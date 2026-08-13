<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../includes/admin-guard.php';
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../../../core/queue/queue.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Use GET.']);
    exit;
}

$rawIds = $_GET['job_ids'] ?? $_GET['ids'] ?? '';
$values = is_array($rawIds) ? $rawIds : preg_split('/[,\s]+/', (string)$rawIds, -1, PREG_SPLIT_NO_EMPTY);
$jobIds = array_values(array_unique(array_filter(array_map('intval', (array)$values), static fn(int $id): bool => $id > 0)));
$jobIds = array_slice($jobIds, 0, 100);

if ($jobIds === []) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Informe ao menos um job_id.']);
    exit;
}

/** @return array<int,array<string,mixed>> */
function ais_status_queue_rows(array $jobIds): array
{
    $found = [];
    try {
        if (sv_queue_uses_file_backend()) {
            $data = sv_queue_file_bootstrap();
            $wanted = array_fill_keys($jobIds, true);
            foreach ((array)($data['tasks'] ?? []) as $row) {
                $id = (int)($row['id'] ?? 0);
                if (!isset($wanted[$id])) continue;
                $found[$id] = $row;
            }
            return $found;
        }

        $pdo = sv_queue_db();
        $placeholders = implode(',', array_fill(0, count($jobIds), '?'));
        $stmt = $pdo->prepare(
            "SELECT id, job_type, payload, status, priority, available_at, started_at, finished_at, attempts, last_error, created_at "
            . "FROM queue_jobs WHERE id IN ({$placeholders})"
        );
        $stmt->execute($jobIds);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $found[(int)$row['id']] = $row;
        }
    } catch (Throwable $e) {
        error_log('[ai-image-studio] generation_status queue: ' . $e->getMessage());
    }
    return $found;
}

/** @return list<array<string,mixed>> */
function ais_status_staging_rows(PDO $db, int $productId, string $channel, string $requestedAt): array
{
    if ($productId <= 0 || $channel === '') return [];
    $since = strtotime($requestedAt);
    $sinceSql = $since !== false ? date('Y-m-d H:i:s', $since - 120) : date('Y-m-d H:i:s', time() - 86400);
    try {
        $stmt = $db->prepare(
            'SELECT id, product_id, image_type, provider_used, status, local_path, error_message, created_at, updated_at '
            . 'FROM product_images_staging '
            . 'WHERE product_id = ? AND target_channels_json LIKE ? AND created_at >= ? '
            . 'ORDER BY created_at DESC, id DESC LIMIT 20'
        );
        $stmt->execute([$productId, '%"' . $channel . '"%', $sinceSql]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $latestByType = [];
        foreach ($rows as $row) {
            $type = strtolower(trim((string)($row['image_type'] ?? '')));
            if ($type === '' || isset($latestByType[$type])) continue;
            $latestByType[$type] = [
                'staging_id' => (int)($row['id'] ?? 0),
                'image_type' => $type,
                'provider_used' => (string)($row['provider_used'] ?? ''),
                'status' => (string)($row['status'] ?? ''),
                'local_path' => (string)($row['local_path'] ?? ''),
                'error' => (string)($row['error_message'] ?? ''),
                'created_at' => (string)($row['created_at'] ?? ''),
                'updated_at' => (string)($row['updated_at'] ?? ''),
            ];
        }
        return array_values($latestByType);
    } catch (Throwable $e) {
        error_log('[ai-image-studio] generation_status staging: ' . $e->getMessage());
        return [];
    }
}

$queueRows = ais_status_queue_rows($jobIds);
$db = ai_studio_db();
$jobs = [];
$summary = ['queued' => 0, 'running' => 0, 'done' => 0, 'failed' => 0, 'unknown' => 0, 'total' => count($jobIds)];

foreach ($jobIds as $jobId) {
    $row = $queueRows[$jobId] ?? null;
    if (!is_array($row)) {
        $summary['unknown']++;
        $jobs[] = [
            'job_id' => $jobId,
            'status' => 'unknown',
            'terminal' => false,
            'result_state' => 'unknown',
            'message' => 'Job nao encontrado no backend de fila atual.',
            'staging' => [],
        ];
        continue;
    }

    $payload = $row['payload'] ?? [];
    if (is_string($payload)) {
        $decoded = json_decode($payload, true);
        $payload = is_array($decoded) ? $decoded : [];
    }
    if (!is_array($payload)) $payload = [];

    $status = strtolower(trim((string)($row['status'] ?? 'unknown')));
    if (!isset($summary[$status])) $summary[$status] = 0;
    $summary[$status]++;
    $productId = (int)($payload['product_id'] ?? 0);
    $channel = strtolower(trim((string)($payload['target_channel'] ?? 'site')));
    $requestedAt = (string)($payload['requested_at'] ?? $row['created_at'] ?? '');
    $staging = $db instanceof PDO ? ais_status_staging_rows($db, $productId, $channel, $requestedAt) : [];

    $stagingSuccess = count(array_filter($staging, static fn(array $item): bool => in_array((string)($item['status'] ?? ''), ['pending', 'published', 'submitted', 'partial_published'], true)));
    $stagingFailed = count(array_filter($staging, static fn(array $item): bool => in_array((string)($item['status'] ?? ''), ['failed', 'publication_failed'], true)));
    $terminal = in_array($status, ['done', 'failed'], true);
    $resultState = match (true) {
        $status === 'failed' => 'failed',
        $status === 'done' && $stagingSuccess > 0 => 'ready_for_review',
        $status === 'done' && $stagingFailed > 0 => 'failed',
        $status === 'done' => 'done_without_visible_staging',
        $status === 'running' => 'generating',
        $status === 'queued' => 'queued',
        default => 'unknown',
    };

    $jobs[] = [
        'job_id' => $jobId,
        'job_type' => (string)($row['job_type'] ?? ''),
        'status' => $status,
        'terminal' => $terminal,
        'result_state' => $resultState,
        'product_id' => $productId,
        'provider' => (string)($payload['provider'] ?? ''),
        'target_channel' => $channel,
        'image_types' => array_values(array_map('strval', (array)($payload['image_types'] ?? []))),
        'attempts' => (int)($row['attempts'] ?? 0),
        'created_at' => (string)($row['created_at'] ?? ''),
        'started_at' => $row['started_at'] ?? null,
        'finished_at' => $row['finished_at'] ?? null,
        'last_error' => (string)($row['last_error'] ?? ''),
        'staging' => $staging,
    ];
}

echo json_encode([
    'success' => true,
    'jobs' => $jobs,
    'summary' => $summary,
    'queue' => function_exists('sv_queue_summary') ? sv_queue_summary() : null,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

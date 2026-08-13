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
$requestedJobCount = count($jobIds);
$jobIds = array_slice($jobIds, 0, 100);

if ($jobIds === []) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Informe ao menos um job_id.']);
    exit;
}

function ais_sanitize_error(string $error): string
{
    $error = trim($error);
    if ($error === '') return '';
    $patterns = [
        '/(?:sk-(?:proj-)?|AIza|ant-api)[A-Za-z0-9_\-]{8,}/i' => '[redacted]',
        '/Bearer\s+[A-Za-z0-9._~+\-\/=]+/i' => 'Bearer [redacted]',
        '/([?&](?:key|api_key|token|access_token|refresh_token)=)[^&\s]+/i' => '$1[redacted]',
        '/((?:api[_-]?key|authorization|access[_-]?token|refresh[_-]?token|secret)\s*[:=]\s*["\']?)[^\s"\'&,;]+/i' => '$1[redacted]',
    ];
    foreach ($patterns as $pattern => $replacement) {
        $error = preg_replace($pattern, $replacement, $error) ?? $error;
    }
    $error = preg_replace('/\s+/u', ' ', $error) ?? $error;
    return mb_substr(trim($error), 0, 360, 'UTF-8');
}

/** @return array<int,array<string,mixed>> */
function ais_status_queue_rows(array $jobIds): array
{
    try {
        $found = [];
        if (sv_queue_uses_file_backend()) {
            $data = sv_queue_file_bootstrap();
            $wanted = array_fill_keys($jobIds, true);
            foreach ((array)($data['tasks'] ?? []) as $row) {
                $id = (int)($row['id'] ?? 0);
                if (isset($wanted[$id])) $found[$id] = $row;
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
        return $found;
    } catch (Throwable $e) {
        error_log('[ai-image-studio] generation_status queue: ' . ais_sanitize_error($e->getMessage()));
        throw new RuntimeException('Backend de fila indisponivel.', 0, $e);
    }
}

/** @return list<array<string,mixed>> */
function ais_status_staging_rows(PDO $db, int $jobId, int $productId): array
{
    if ($jobId <= 0 || $productId <= 0) return [];
    try {
        $stmt = $db->prepare(
            'SELECT id, source_job_id, product_id, image_type, provider_used, status, local_path, error_message, created_at, updated_at '
            . 'FROM product_images_staging '
            . 'WHERE source_job_id = ? AND product_id = ? '
            . 'ORDER BY id DESC LIMIT 20'
        );
        $stmt->execute([$jobId, $productId]);
        $latestByType = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $type = strtolower(trim((string)($row['image_type'] ?? '')));
            if ($type === '' || isset($latestByType[$type])) continue;
            $latestByType[$type] = [
                'staging_id' => (int)($row['id'] ?? 0),
                'source_job_id' => (int)($row['source_job_id'] ?? 0),
                'image_type' => $type,
                'provider_used' => (string)($row['provider_used'] ?? ''),
                'status' => strtolower(trim((string)($row['status'] ?? ''))),
                'local_path' => (string)($row['local_path'] ?? ''),
                'error' => ais_sanitize_error((string)($row['error_message'] ?? '')),
                'created_at' => (string)($row['created_at'] ?? ''),
                'updated_at' => (string)($row['updated_at'] ?? ''),
            ];
        }
        return array_values($latestByType);
    } catch (Throwable $e) {
        error_log('[ai-image-studio] generation_status staging: ' . ais_sanitize_error($e->getMessage()));
        throw new RuntimeException('Staging de imagens indisponivel.', 0, $e);
    }
}

try {
    $queueRows = ais_status_queue_rows($jobIds);
    $db = function_exists('ai_studio_db') ? ai_studio_db() : null;
    if (!$db instanceof PDO) throw new RuntimeException('Banco do Image Studio indisponivel.');
    svcp_ensure_schema($db);

    $jobs = [];
    $summary = ['queued' => 0, 'running' => 0, 'done' => 0, 'failed' => 0, 'unknown' => 0, 'partial_failure' => 0, 'total' => count($jobIds)];

    foreach ($jobIds as $jobId) {
        $row = $queueRows[$jobId] ?? null;
        if (!is_array($row)) {
            $summary['unknown']++;
            $jobs[] = [
                'id' => $jobId,
                'job_id' => $jobId,
                'status' => 'unknown',
                'terminal' => false,
                'result_state' => 'unknown',
                'message' => 'Job nao encontrado no backend de fila atual.',
                'variants' => ['requested' => 0, 'ready_for_review' => 0, 'failed' => 0, 'missing' => []],
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
        $requestedTypes = array_values(array_unique(array_filter(array_map(
            static fn(mixed $type): string => strtolower(trim((string)$type)),
            (array)($payload['image_types'] ?? [])
        ))));
        $staging = ais_status_staging_rows($db, $jobId, $productId);
        $stagingByType = [];
        foreach ($staging as $stagingRow) {
            $type = (string)($stagingRow['image_type'] ?? '');
            if ($type !== '') $stagingByType[$type] = $stagingRow;
        }

        $readyStatuses = ['pending', 'published', 'submitted', 'partial_published'];
        $failedStatuses = ['failed', 'publication_failed'];
        $readyTypes = [];
        $failedTypes = [];
        foreach ($requestedTypes as $type) {
            $itemStatus = (string)($stagingByType[$type]['status'] ?? '');
            if (in_array($itemStatus, $readyStatuses, true)) $readyTypes[] = $type;
            elseif (in_array($itemStatus, $failedStatuses, true)) $failedTypes[] = $type;
        }
        $missingTypes = array_values(array_diff($requestedTypes, $readyTypes, $failedTypes));
        $expected = count($requestedTypes);
        $readyCount = count($readyTypes);
        $failedCount = count($failedTypes);
        $terminal = in_array($status, ['done', 'failed'], true);

        $resultState = match (true) {
            $status === 'failed' => 'failed',
            $status === 'done' && $expected > 0 && $readyCount === $expected => 'ready_for_review',
            $status === 'done' && $readyCount > 0 && ($failedCount > 0 || $missingTypes !== []) => 'partial_failure',
            $status === 'done' && $failedCount > 0 => 'failed',
            $status === 'done' => 'done_without_visible_staging',
            $status === 'running' => 'generating',
            $status === 'queued' => 'queued',
            default => 'unknown',
        };
        if ($resultState === 'partial_failure') $summary['partial_failure']++;

        $jobs[] = [
            'id' => $jobId,
            'job_id' => $jobId,
            'job_type' => (string)($row['job_type'] ?? ''),
            'status' => $status,
            'terminal' => $terminal,
            'result_state' => $resultState,
            'product_id' => $productId,
            'provider' => (string)($payload['provider'] ?? ''),
            'target_channel' => $channel,
            'image_types' => $requestedTypes,
            'variants' => [
                'requested' => $expected,
                'ready_for_review' => $readyCount,
                'failed' => $failedCount,
                'missing' => $missingTypes,
                'ready_types' => $readyTypes,
                'failed_types' => $failedTypes,
            ],
            'attempts' => (int)($row['attempts'] ?? 0),
            'created_at' => (string)($row['created_at'] ?? ''),
            'started_at' => $row['started_at'] ?? null,
            'finished_at' => $row['finished_at'] ?? null,
            'last_error' => ais_sanitize_error((string)($row['last_error'] ?? '')),
            'staging' => $staging,
        ];
    }

    echo json_encode([
        'success' => true,
        'jobs' => $jobs,
        'summary' => $summary,
        'request' => [
            'requested_job_count' => $requestedJobCount,
            'returned_job_count' => count($jobIds),
            'limit' => 100,
            'truncated' => $requestedJobCount > count($jobIds),
        ],
        'queue' => function_exists('sv_queue_summary') ? sv_queue_summary() : null,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    error_log('[ai-image-studio] generation_status: ' . ais_sanitize_error($e->getMessage()));
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Nao foi possivel consultar o andamento da fila.',
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

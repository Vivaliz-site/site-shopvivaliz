<?php

declare(strict_types=1);

require_once __DIR__ . '/../admin/ai-image-studio/process_item.php';
require_once __DIR__ . '/../core/queue/queue.php';

$once = in_array('--once', $argv, true);
$limit = 3;
$idleSleep = 5;
foreach ($argv as $arg) {
    if (str_starts_with((string)$arg, '--limit=')) {
        $limit = max(1, (int)substr((string)$arg, 8));
    }
    if (str_starts_with((string)$arg, '--sleep=')) {
        $idleSleep = max(1, (int)substr((string)$arg, 8));
    }
}

$db = ai_studio_db();
if (!$db instanceof PDO) {
    fwrite(STDERR, "[ai-image-worker] Banco de dados indisponivel; resultados nao poderiam ser persistidos em staging.\n");
    sv_log('ai_image_worker_database_unavailable', 'queue', ['once' => $once]);
    exit(2);
}
svcp_ensure_schema($db);

fwrite(STDOUT, "[ai-image-worker] Starting with limit={$limit}, idle_sleep={$idleSleep}s\n");
sv_log('ai_image_worker_started', 'queue', ['limit' => $limit, 'idle_sleep' => $idleSleep, 'once' => $once]);

while (true) {
    $jobs = sv_queue_claim($limit);
    if ($jobs === []) {
        fwrite(STDOUT, "[ai-image-worker] No queued jobs, sleeping...\n");
        sv_log('ai_image_worker_idle', 'queue', ['limit' => $limit, 'idle_sleep' => $idleSleep, 'once' => $once]);
        if ($once) {
            exit(0);
        }
        sleep($idleSleep);
        continue;
    }

    foreach ($jobs as $job) {
        $jobId = (int)($job['id'] ?? 0);
        $payload = json_decode((string)($job['payload'] ?? '{}'), true);
        if (!is_array($payload)) {
            sv_queue_finish($jobId, 'failed', 'payload invalid');
            continue;
        }

        try {
            $productId = (int)($payload['product_id'] ?? 0);
            $provider = (string)($payload['provider'] ?? '');
            $imageTypes = is_array($payload['image_types'] ?? null)
                ? array_values(array_map('strval', $payload['image_types']))
                : ['cover', 'hero', 'ambient'];
            $modelOverride = trim((string)($payload['model_override'] ?? ''));
            $targetChannel = strtolower(trim((string)($payload['target_channel'] ?? 'site')));
            $product = is_array($payload['product'] ?? null) ? $payload['product'] : null;
            $baseImagePath = trim((string)($payload['base_image_path'] ?? ''));
            $baseImageIsTemp = (bool)($payload['base_image_is_temp'] ?? false);
            $prompts = is_array($payload['prompts'] ?? null) ? $payload['prompts'] : null;

            // A fila precisa usar a conexao real. O fluxo antigo chamava o
            // helper com PDO nulo: os arquivos podiam ser gerados, mas nenhuma
            // linha era inserida em product_images_staging, deixando o Admin
            // sem resultados para revisar ou publicar.
            $result = ai_studio_process_item(
                $db,
                $productId,
                $provider,
                $imageTypes,
                $modelOverride !== '' ? $modelOverride : null,
                $targetChannel,
                $product,
                $baseImagePath !== '' ? $baseImagePath : null,
                $baseImageIsTemp,
                $prompts
            );

            $success = (bool)($result['success'] ?? false);
            $resultErrors = [];
            $stagingIds = [];
            foreach ((array)($result['results'] ?? []) as $row) {
                $stagingId = (int)($row['staging_id'] ?? 0);
                if ($stagingId > 0) $stagingIds[] = $stagingId;
                $error = trim((string)($row['error'] ?? ''));
                if ($error !== '') $resultErrors[] = $error;
            }
            $stagingIds = array_values(array_unique($stagingIds));

            // Correlacao forte: somente as linhas efetivamente retornadas por
            // esta execucao recebem o job da fila. O endpoint de status usa
            // source_job_id em vez de uma janela temporal que poderia misturar
            // regeneracoes simultaneas do mesmo produto/canal.
            if ($stagingIds !== []) {
                $placeholders = implode(',', array_fill(0, count($stagingIds), '?'));
                $stamp = $db->prepare(
                    "UPDATE product_images_staging SET source_job_id = ?, updated_at = updated_at "
                    . "WHERE product_id = ? AND id IN ({$placeholders})"
                );
                $stamp->execute(array_merge([$jobId, $productId], $stagingIds));
                if ($stamp->rowCount() < count($stagingIds)) {
                    throw new RuntimeException('Nao foi possivel correlacionar todos os resultados de staging ao job da fila.');
                }
            }

            $errorText = $success ? null : (trim((string)($result['error'] ?? '')) ?: implode(' | ', $resultErrors));
            sv_queue_finish($jobId, $success ? 'done' : 'failed', $errorText !== '' ? $errorText : null);

            fwrite(STDOUT, "[ai-image-worker] Job {$jobId} finished with status=" . ($success ? 'done' : 'failed') . " staging=" . implode(',', $stagingIds) . "\n");
            sv_log('ai_image_worker_job_finished', 'queue', [
                'job_id' => $jobId,
                'status' => $success ? 'done' : 'failed',
                'product_id' => $productId,
                'provider' => $provider,
                'target_channel' => $targetChannel,
                'staging_ids' => $stagingIds,
                'result_count' => count((array)($result['results'] ?? [])),
            ]);
        } catch (Throwable $e) {
            sv_queue_finish($jobId, 'failed', $e->getMessage());
            fwrite(STDERR, "[ai-image-worker] Job {$jobId} failed: {$e->getMessage()}\n");
            sv_log('ai_image_worker_job_failed', 'queue', [
                'job_id' => $jobId,
                'error' => $e->getMessage(),
                'product_id' => (int)($payload['product_id'] ?? 0),
                'provider' => (string)($payload['provider'] ?? ''),
                'target_channel' => (string)($payload['target_channel'] ?? ''),
            ]);
        }
    }

    if ($once) {
        exit(0);
    }
}

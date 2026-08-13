<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../includes/admin-guard.php';
require_once __DIR__ . '/../process_item.php';
require_once __DIR__ . '/../src/ImageChannelProfile.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Use POST.']);
    exit;
}

$projectRoot = dirname(__DIR__, 3);
$storefrontResolver = $projectRoot . '/includes/storefront-image-source.php';
if (is_file($storefrontResolver)) {
    require_once $storefrontResolver;
}

/** @return list<string> */
function ais_enqueue_image_candidates(PDO $db, array $product, string $projectRoot): array
{
    $candidates = [];
    $sku = trim((string)($product['sku'] ?? ''));

    if ($sku !== '') {
        try {
            $stmt = $db->prepare('SELECT * FROM olist_product_images WHERE sku = ? LIMIT 20');
            $stmt->execute([$sku]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
            usort($rows, static function (array $a, array $b): int {
                return [-(int)($a['is_primary'] ?? 0), (int)($a['position'] ?? 9999), (int)($a['id'] ?? 999999)]
                    <=> [-(int)($b['is_primary'] ?? 0), (int)($b['position'] ?? 9999), (int)($b['id'] ?? 999999)];
            });
            foreach ($rows as $row) {
                $status = strtolower(trim((string)($row['status'] ?? '')));
                if (in_array($status, ['error', 'deleted', 'inactive'], true)) {
                    continue;
                }
                $resolved = '';
                if (function_exists('svsis_resolve_image_url')) {
                    $resolved = trim((string)svsis_resolve_image_url($row, $projectRoot));
                }
                if ($resolved === '') {
                    foreach (['original_url_olist', 'image_url', 'original_url', 'site_url', 'local_url'] as $field) {
                        $value = trim((string)($row[$field] ?? ''));
                        if ($value === '') continue;
                        if (preg_match('~^https?://~i', $value) === 1 || str_starts_with($value, '/')) {
                            $resolved = $value;
                            break;
                        }
                    }
                }
                if ($resolved !== '' && !in_array($resolved, $candidates, true)) {
                    $candidates[] = $resolved;
                }
            }
        } catch (Throwable $e) {
            error_log('[ai-image-studio] Falha ao consultar galeria reconciliada SKU ' . $sku . ': ' . $e->getMessage());
        }
    }

    $legacy = trim((string)($product['image_ref'] ?? ''));
    if ($legacy !== '' && !in_array($legacy, $candidates, true)) {
        $candidates[] = $legacy;
    }
    return $candidates;
}

/** @return array<string,mixed>|null */
function ais_enqueue_existing_job(int $productId, string $targetChannel): ?array
{
    try {
        $rows = [];
        if (sv_queue_uses_file_backend()) {
            $data = sv_queue_file_bootstrap();
            $rows = array_reverse((array)($data['tasks'] ?? []));
        } else {
            $pdo = sv_queue_db();
            $stmt = $pdo->prepare(
                "SELECT id, job_type, payload, status, attempts, created_at, started_at, finished_at, last_error "
                . "FROM queue_jobs WHERE job_type = ? AND status IN ('queued','running') ORDER BY id DESC LIMIT 250"
            );
            $stmt->execute(['ai_image_studio.process_item']);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        }

        foreach ($rows as $row) {
            if ((string)($row['job_type'] ?? '') !== 'ai_image_studio.process_item') continue;
            $status = strtolower(trim((string)($row['status'] ?? '')));
            if (!in_array($status, ['queued', 'running'], true)) continue;
            $payload = $row['payload'] ?? [];
            if (is_string($payload)) {
                $decoded = json_decode($payload, true);
                $payload = is_array($decoded) ? $decoded : [];
            }
            if (!is_array($payload)) continue;
            if ((int)($payload['product_id'] ?? 0) !== $productId) continue;
            if (strtolower(trim((string)($payload['target_channel'] ?? 'site'))) !== $targetChannel) continue;
            return [
                'id' => (int)($row['id'] ?? 0),
                'status' => $status,
                'attempts' => (int)($row['attempts'] ?? 0),
                'created_at' => (string)($row['created_at'] ?? ''),
                'started_at' => $row['started_at'] ?? null,
                'image_types' => array_values(array_map('strval', (array)($payload['image_types'] ?? []))),
                'provider' => (string)($payload['provider'] ?? ''),
            ];
        }
    } catch (Throwable $e) {
        error_log('[ai-image-studio] deduplicacao de fila indisponivel: ' . $e->getMessage());
    }
    return null;
}

$rawBody = file_get_contents('php://input') ?: '';
$json = json_decode($rawBody, true);
$input = is_array($json) ? $json : $_POST;

$productId = (int)($input['product_id'] ?? 0);
$provider = ai_studio_normalize_provider((string)($input['provider'] ?? ''));
$targetChannel = strtolower(trim((string)($input['target_channel'] ?? 'site')));
$model = trim((string)($input['model'] ?? $input['model_override'] ?? ''));
$rawTypes = $input['image_types'] ?? ai_studio_channel_recommended_types($targetChannel);
$imageTypes = is_array($rawTypes)
    ? array_values(array_unique(array_intersect(array_map('strval', $rawTypes), ['cover', 'white', 'hero', 'ambient'])))
    : [];

if ($productId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Selecione um produto valido.']);
    exit;
}
if (!in_array($provider, ['openai', 'google', 'claude', 'openrouter', 'groq'], true)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Selecione um provedor valido.']);
    exit;
}
if (!isset(ai_studio_channel_profiles()[$targetChannel])) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Marketplace de destino invalido.']);
    exit;
}
if ($imageTypes === []) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Marque ao menos um tipo de imagem para este produto.']);
    exit;
}

$db = ai_studio_db();
if (!$db instanceof PDO) {
    http_response_code(503);
    echo json_encode(['success' => false, 'error' => 'Banco de dados temporariamente indisponivel.']);
    exit;
}

$product = ai_studio_fetch_product($db, $productId);
if ($product === null) {
    http_response_code(404);
    echo json_encode(['success' => false, 'error' => "Produto #{$productId} nao encontrado ou sem nome."]);
    exit;
}

$providers = ai_studio_image_provider_candidates($provider);
if ($providers === []) {
    http_response_code(503);
    echo json_encode(['success' => false, 'error' => 'Nenhum editor visual possui chave ativa disponivel para concluir a imagem.']);
    exit;
}

$existing = ais_enqueue_existing_job($productId, $targetChannel);
if (is_array($existing) && (int)($existing['id'] ?? 0) > 0) {
    http_response_code(202);
    echo json_encode([
        'success' => true,
        'queued' => true,
        'deduplicated' => true,
        'job_id' => (int)$existing['id'],
        'job_status' => (string)$existing['status'],
        'product_id' => $productId,
        'provider_requested' => $provider,
        'provider_in_queue' => (string)($existing['provider'] ?? ''),
        'target_channel' => $targetChannel,
        'image_types' => (array)($existing['image_types'] ?? []),
        'message' => 'Este produto ja possui uma geracao em andamento para o mesmo canal. A fila existente foi reutilizada.',
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$baseImagePath = null;
$baseImageIsTemp = false;
$imageSource = '';
$sourceErrors = [];
foreach (ais_enqueue_image_candidates($db, $product, $projectRoot) as $candidate) {
    try {
        $baseImagePath = ai_studio_resolve_base_image($candidate, $projectRoot, $productId);
        $baseImageIsTemp = str_starts_with($baseImagePath, AI_STUDIO_BASE_IMAGE_TMP_DIR);
        $imageSource = $candidate;
        break;
    } catch (Throwable $e) {
        $sourceErrors[] = $e->getMessage();
    }
}

if (!is_string($baseImagePath) || $baseImagePath === '') {
    http_response_code(422);
    $suffix = $sourceErrors !== [] ? ' Ultimo erro: ' . (string)end($sourceErrors) : '';
    echo json_encode([
        'success' => false,
        'error' => 'Nenhuma foto real valida foi encontrada. Sincronize a galeria do ERP/Tiny ou corrija a foto principal.' . $suffix,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$product['image_ref'] = $imageSource;
$prompts = ai_studio_default_prompts((string)$product['name'], $targetChannel, $product);

try {
    $jobId = sv_queue_enqueue('ai_image_studio.process_item', [
        'product_id' => $productId,
        'provider' => $provider,
        'image_types' => $imageTypes,
        'model_override' => $model !== '' ? $model : null,
        'target_channel' => $targetChannel,
        'product' => $product,
        'prompts' => $prompts,
        'base_image_path' => $baseImagePath,
        'base_image_is_temp' => $baseImageIsTemp,
        'requested_at' => gmdate(DATE_ATOM),
    ], 25);

    sv_log('ai_image_studio_job_enqueued_resilient', 'queue', [
        'job_id' => $jobId,
        'product_id' => $productId,
        'provider' => $provider,
        'target_channel' => $targetChannel,
        'types' => $imageTypes,
        'source' => preg_match('~^https?://~i', $imageSource) ? 'remote' : 'local',
    ]);

    http_response_code(202);
    echo json_encode([
        'success' => true,
        'queued' => true,
        'deduplicated' => false,
        'job_id' => $jobId,
        'job_status' => 'queued',
        'product_id' => $productId,
        'provider_requested' => $provider,
        'provider_role' => in_array($provider, ['groq', 'claude'], true) ? 'prompt_optimizer_plus_visual_editor' : 'visual_editor',
        'provider_candidates' => $providers,
        'target_channel' => $targetChannel,
        'image_types' => $imageTypes,
        'recommended_types' => ai_studio_channel_recommended_types($targetChannel),
        'queue' => function_exists('sv_queue_summary') ? sv_queue_summary() : null,
        'message' => 'Produto enfileirado. A tela pode acompanhar o status sem perder a selecao.',
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    if ($baseImageIsTemp && is_string($baseImagePath) && is_file($baseImagePath)) {
        @unlink($baseImagePath);
    }
    error_log('[ai-image-studio] Falha ao enfileirar produto #' . $productId . ': ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Nao foi possivel enfileirar a geracao: ' . $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

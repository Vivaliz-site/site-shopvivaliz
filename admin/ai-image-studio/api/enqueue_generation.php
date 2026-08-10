<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../includes/admin-guard.php';
require_once __DIR__ . '/../process_item.php';

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

/**
 * Resolve a melhor foto real disponivel para a geracao.
 * Prioriza as imagens reconciliadas do ERP/Tiny, porque releases imutaveis
 * podem nao conter uploads locais historicos ainda gravados em products.image_url.
 */
function ais_enqueue_image_candidates(PDO $db, array $product, string $projectRoot): array
{
    $candidates = [];
    $sku = trim((string)($product['sku'] ?? ''));

    if ($sku !== '') {
        try {
            $stmt = $db->prepare(
                "SELECT site_url, local_url, image_url, original_url_olist, original_url, position, is_primary, id "
                . "FROM olist_product_images "
                . "WHERE sku = ? AND (status IS NULL OR status = '' OR status NOT IN ('error','deleted','inactive')) "
                . "ORDER BY is_primary DESC, position ASC, id ASC LIMIT 12"
            );
            $stmt->execute([$sku]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
                $resolved = '';
                if (function_exists('svsis_resolve_image_url')) {
                    $resolved = trim((string)svsis_resolve_image_url($row, $projectRoot));
                } else {
                    foreach (['original_url_olist', 'image_url', 'original_url', 'site_url', 'local_url'] as $field) {
                        $value = trim((string)($row[$field] ?? ''));
                        if ($value === '') continue;
                        if (preg_match('~^https?://~i', $value)) {
                            $resolved = $value;
                            break;
                        }
                        if (str_starts_with($value, '/') && is_file($projectRoot . $value)) {
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

$rawBody = file_get_contents('php://input') ?: '';
$json = json_decode($rawBody, true);
$input = is_array($json) ? $json : $_POST;

$productId = (int)($input['product_id'] ?? 0);
$provider = ai_studio_normalize_provider((string)($input['provider'] ?? ''));
$targetChannel = strtolower(trim((string)($input['target_channel'] ?? 'site')));
$model = trim((string)($input['model'] ?? $input['model_override'] ?? ''));
$rawTypes = $input['image_types'] ?? ['white', 'hero', 'ambient'];
$imageTypes = is_array($rawTypes) ? array_values(array_unique(array_intersect(array_map('strval', $rawTypes), ['white', 'hero', 'ambient']))) : [];

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
    echo json_encode(['success' => false, 'error' => 'Nenhum provedor de imagem possui chave ativa disponivel.']);
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
    $suffix = $sourceErrors !== [] ? ' Ultimo erro: ' . end($sourceErrors) : '';
    echo json_encode([
        'success' => false,
        'error' => 'Nenhuma foto real valida foi encontrada para este produto. Sincronize a galeria do ERP/Tiny ou corrija a foto principal.' . $suffix,
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
        'source' => preg_match('~^https?://~i', $imageSource) ? 'remote' : 'local',
    ]);

    http_response_code(202);
    echo json_encode([
        'success' => true,
        'queued' => true,
        'job_id' => $jobId,
        'product_id' => $productId,
        'provider_requested' => $provider,
        'provider_candidates' => $providers,
        'target_channel' => $targetChannel,
        'image_types' => $imageTypes,
        'queue' => sv_queue_summary(),
        'message' => 'Produto enfileirado. A tela pode ser atualizada depois para acompanhar os resultados.',
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    if ($baseImageIsTemp && is_string($baseImagePath) && is_file($baseImagePath)) {
        @unlink($baseImagePath);
    }
    error_log('[ai-image-studio] Falha ao enfileirar produto #' . $productId . ': ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Nao foi possivel enfileirar a geracao: ' . $e->getMessage()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

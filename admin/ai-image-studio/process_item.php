<?php

declare(strict_types=1);

/**
 * AI Image Studio - processamento de uma imagem por produto.
 *
 * Regra central: nunca gerar o produto do zero. Toda imagem nasce de uma
 * foto real cadastrada para o MESMO product_id, recebe orientacao especifica
 * do marketplace escolhido e permanece em staging ate revisao humana.
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/src/AiServices.php';
require_once __DIR__ . '/src/ImageChannelProfile.php';
require_once __DIR__ . '/../../includes/catalog-publication-schema.php';
require_once __DIR__ . '/../../includes/product-reference-resolver.php';

/** @return array<string,string> */
function ai_studio_default_prompts(string $productName, string $targetChannel = 'site'): array
{
    $identity = "Use the supplied real photo of {$productName} as the only product reference. Preserve the exact product identity: shape, proportions, color, material appearance, labels, logos, printed text, connectors, controls and included parts. Do not invent, remove, replace or redesign any product feature. Do not add accessories that could be interpreted as included with the product.";

    $base = [
        'white' => $identity . ' Create a marketplace-safe main image: pure white RGB 255,255,255 background, product centered, fully visible and occupying about 85-95% of the frame, neutral professional lighting, natural contact shadow only, no badges, borders, promotional text, watermarks or extra objects. Square 1:1 composition and photorealistic.',
        'hero' => $identity . ' Create a premium ecommerce hero image with controlled studio lighting and a clean neutral setting. Keep the product unobstructed and dominant in frame. No promotional text, badges, watermarks or invented props. Square 1:1 composition and photorealistic.',
        'ambient' => $identity . ' Place the exact product in a realistic usage context supported by the visible product category, without implying unsupported compatibility or accessories. Keep the product fully recognizable and unobstructed. Natural scale, perspective, shadows and lighting. Square 1:1 composition and photorealistic.',
    ];

    foreach ($base as $type => $prompt) {
        $base[$type] = $prompt . ' Marketplace-specific guidance: ' . ai_studio_channel_guidance($targetChannel, $type);
    }
    return $base;
}

/** @return array<string,mixed>|null */
function ai_studio_fetch_product_row(PDO $db, int|string $productReference): ?array
{
    return svpr_resolve_product_row($db, $productReference);
}

function ai_studio_is_active_product_row(array $row): bool
{
    $status = trim((string)($row['status'] ?? $row['situacao'] ?? $row['state'] ?? ''));
    $normalized = function_exists('mb_strtoupper')
        ? mb_strtoupper($status, 'UTF-8')
        : strtoupper($status);

    if (in_array($normalized, ['', 'A', 'ACTIVE', 'ATIVO', '1'], true)) {
        return true;
    }

    foreach (['is_active', 'active', 'ativo', 'enabled', 'published', 'is_published'] as $field) {
        if (!array_key_exists($field, $row)) {
            continue;
        }

        $value = $row[$field];
        if (is_bool($value) && $value) {
            return true;
        }

        $boolean = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        if ($boolean === true) {
            return true;
        }

        if (in_array($value, [1, '1'], true)) {
            return true;
        }
    }

    return false;
}

/** @return array{name:string,description:string,image_ref:string,sku:string,olist_id:string}|null */
function ai_studio_map_product_row(array $row): ?array
{
    if (!ai_studio_is_active_product_row($row)) {
        return null;
    }

    $name = trim((string)($row['name'] ?? $row['nome'] ?? $row['descricao'] ?? ''));
    if ($name === '') {
        return null;
    }

    $description = trim((string)(
        $row['description']
        ?? $row['descricao_completa']
        ?? $row['descricaoComplementar']
        ?? $row['descricao_complementar']
        ?? $row['descricao']
        ?? ''
    ));
    $imageRef = trim((string)(
        $row['image_url']
        ?? $row['imagem_principal_url']
        ?? $row['primary_image_url']
        ?? $row['imagem']
        ?? ''
    ));

    return [
        'name' => $name,
        'description' => $description,
        'image_ref' => $imageRef,
        'sku' => trim((string)($row['sku'] ?? '')),
        'olist_id' => trim((string)($row['olist_id'] ?? '')),
    ];
}

/** @return array{name:string,description:string,image_ref:string,sku:string,olist_id:string}|null */
function ai_studio_fetch_product(PDO $db, int|string $productReference): ?array
{
    $row = ai_studio_fetch_product_row($db, $productReference);
    return is_array($row) ? ai_studio_map_product_row($row) : null;
}

/** @return array{width:int,height:int,mime:string,sha256:string} */
function ai_studio_validate_image_file(string $path, int $minimumSide = 600): array
{
    if (!is_file($path) || !is_readable($path) || (int)filesize($path) <= 0) {
        throw new AiStudioApiException('Arquivo de imagem inexistente, vazio ou ilegivel.');
    }
    $info = @getimagesize($path);
    if (!is_array($info)) throw new AiStudioApiException('O arquivo informado nao e uma imagem valida.');

    $width = (int)($info[0] ?? 0);
    $height = (int)($info[1] ?? 0);
    $mime = strtolower(trim((string)($info['mime'] ?? '')));
    if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) {
        throw new AiStudioApiException("Formato real da imagem nao permitido: {$mime}.");
    }
    if ($width < $minimumSide || $height < $minimumSide) {
        throw new AiStudioApiException("Imagem abaixo da resolucao minima: {$width}x{$height}; minimo {$minimumSide}px por lado.");
    }
    $hash = hash_file('sha256', $path);
    if (!is_string($hash) || $hash === '') throw new AiStudioApiException('Nao foi possivel calcular a identidade da imagem.');
    return ['width' => $width, 'height' => $height, 'mime' => $mime, 'sha256' => $hash];
}

function ai_studio_resolve_base_image(string $imageRef, string $projectRoot, int $productId): string
{
    $imageRef = trim($imageRef);
    if ($imageRef === '') {
        throw new AiStudioApiException("Produto #{$productId} nao tem foto cadastrada. Nao e possivel gerar imagem sem referencia real.");
    }

    if (preg_match('#^https?://#i', $imageRef) === 1) {
        $extension = strtolower((string)pathinfo(parse_url($imageRef, PHP_URL_PATH) ?: '', PATHINFO_EXTENSION));
        $extension = in_array($extension, ['jpg', 'jpeg', 'png', 'webp'], true) ? $extension : 'jpg';
        $tmpPath = AI_STUDIO_BASE_IMAGE_TMP_DIR . 'base-' . $productId . '-' . bin2hex(random_bytes(6)) . '.' . $extension;
        AiStudioHttpClient::downloadToFile($imageRef, $tmpPath);
        try {
            ai_studio_validate_image_file($tmpPath, 600);
        } catch (Throwable $e) {
            @unlink($tmpPath);
            throw $e;
        }
        return $tmpPath;
    }

    $relative = ltrim(str_replace('\\', '/', $imageRef), '/');
    if ($relative === '' || preg_match('#(^|/)\.\.(/|$)#', $relative) === 1) {
        throw new AiStudioApiException("Produto #{$productId}: caminho local de imagem invalido.");
    }
    $root = rtrim($projectRoot, '/');
    $localPath = $root . '/' . $relative;
    if (!is_file($localPath) || !is_readable($localPath)) {
        throw new AiStudioApiException("Produto #{$productId}: foto cadastrada nao foi encontrada no disco.");
    }
    ai_studio_validate_image_file($localPath, 600);
    return $localPath;
}

function ai_studio_unique_filename(int $productId, string $imageType, string $extension = 'png'): string
{
    $safeType = preg_replace('/[^a-z0-9_-]+/i', '-', $imageType) ?: 'image';
    return sprintf('product-%d-%s-%s-%s.%s', $productId, $safeType, date('Ymd-His'), bin2hex(random_bytes(6)), $extension);
}

function ai_studio_insert_staging_row(
    PDO $db,
    int $productId,
    string $imageType,
    string $providerUsed,
    ?string $localPath,
    ?string $promptUsed,
    string $status,
    ?string $errorMessage = null,
    string $targetChannel = 'site'
): int {
    $targets = json_encode([$targetChannel], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $stmt = $db->prepare(
        'INSERT INTO product_images_staging '
        . '(product_id, image_type, provider_used, local_path, prompt_used, status, error_message, target_channels_json, created_at, updated_at) '
        . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())'
    );
    $stmt->execute([
        $productId,
        $imageType,
        $providerUsed,
        $localPath ?? '',
        $promptUsed,
        $status,
        $errorMessage,
        is_string($targets) ? $targets : '[]',
    ]);
    return (int)$db->lastInsertId();
}

/** @return array<string,mixed> */
function ai_studio_process_item(
    PDO $db,
    int|string $productReference,
    string $provider,
    array $imageTypes = ['white', 'hero', 'ambient'],
    ?string $modelOverride = null,
    string $targetChannel = 'site'
): array {
    svcp_ensure_schema($db);
    $productReference = trim((string)$productReference);
    $provider = strtolower(trim($provider));
    $targetChannel = strtolower(trim($targetChannel));
    $profiles = ai_studio_channel_profiles();
    if (!in_array($provider, ['openai', 'google', 'claude'], true)) {
        return ['success' => false, 'product_id' => 0, 'product_ref' => $productReference, 'provider' => $provider, 'results' => [], 'error' => "Provider invalido: '{$provider}'."];
    }
    if (!isset($profiles[$targetChannel])) {
        return ['success' => false, 'product_id' => 0, 'product_ref' => $productReference, 'provider' => $provider, 'target_channel' => $targetChannel, 'results' => [], 'error' => "Canal de imagem invalido: '{$targetChannel}'."];
    }

    $imageTypes = array_values(array_unique(array_intersect(array_map('strval', $imageTypes), ['white', 'hero', 'ambient'])));
    if ($imageTypes === []) {
        return ['success' => false, 'product_id' => 0, 'product_ref' => $productReference, 'provider' => $provider, 'target_channel' => $targetChannel, 'results' => [], 'error' => 'Nenhum tipo de imagem valido selecionado.'];
    }
    if ($productReference === '') {
        return ['success' => false, 'product_id' => 0, 'product_ref' => '', 'provider' => $provider, 'target_channel' => $targetChannel, 'results' => [], 'error' => 'Referencia do produto ausente.'];
    }

    $productRow = ai_studio_fetch_product_row($db, $productReference);
    if ($productRow === null) {
        return ['success' => false, 'product_id' => 0, 'product_ref' => $productReference, 'provider' => $provider, 'target_channel' => $targetChannel, 'results' => [], 'error' => "Produto #{$productReference} nao encontrado."];
    }
    $resolvedProductId = (int)($productRow['id'] ?? 0);
    if ($resolvedProductId <= 0) {
        return ['success' => false, 'product_id' => 0, 'product_ref' => $productReference, 'provider' => $provider, 'target_channel' => $targetChannel, 'results' => [], 'error' => "Produto #{$productReference} nao foi resolvido para o cadastro local."];
    }
    if (!ai_studio_is_active_product_row($productRow)) {
        return ['success' => false, 'product_id' => $resolvedProductId, 'product_ref' => $productReference, 'provider' => $provider, 'target_channel' => $targetChannel, 'results' => [], 'error' => "Produto #{$productReference} esta inativo e nao pode gerar imagem."];
    }

    $product = ai_studio_map_product_row($productRow);
    if ($product === null) {
        return ['success' => false, 'product_id' => $resolvedProductId, 'product_ref' => $productReference, 'provider' => $provider, 'target_channel' => $targetChannel, 'results' => [], 'error' => "Produto #{$productReference} esta sem nome valido ou bloqueado para geracao."];
    }

    $profile = ai_studio_channel_profile($targetChannel);
    $minimumSide = max(1000, (int)($profile['minimum_side'] ?? 1000));
    $recommendedSide = max($minimumSide, (int)($profile['recommended_side'] ?? $minimumSide));
    $baseImagePath = null;
    $baseImageIsTemp = false;

    try {
        try {
            $baseImagePath = ai_studio_resolve_base_image($product['image_ref'], dirname(__DIR__, 2), $resolvedProductId);
            $baseImageIsTemp = str_starts_with($baseImagePath, AI_STUDIO_BASE_IMAGE_TMP_DIR);
        } catch (Throwable $e) {
            $results = [];
            foreach ($imageTypes as $imageType) {
                $id = ai_studio_insert_staging_row($db, $resolvedProductId, $imageType, $provider === 'claude' ? 'claude_optimized' : $provider, null, null, 'failed', $e->getMessage(), $targetChannel);
                $results[] = ['image_type' => $imageType, 'status' => 'error', 'staging_id' => $id, 'error' => $e->getMessage()];
            }
            return ['success' => false, 'product_id' => $resolvedProductId, 'product_ref' => $productReference, 'provider' => $provider, 'target_channel' => $targetChannel, 'results' => $results, 'error' => 'Foto base invalida: ' . $e->getMessage()];
        }

        $imageEngine = $provider;
        $providerUsed = $provider;
        $prompts = ai_studio_default_prompts($product['name'], $targetChannel);

        if ($provider === 'claude') {
            try {
                $optimized = (new AiStudioClaudeClient(AI_STUDIO_CLAUDE_API_KEY, AI_STUDIO_CLAUDE_MODEL))
                    ->optimizePrompts($product['name'], $product['description']);
                foreach ($prompts as $type => $guardedPrompt) {
                    $candidate = trim((string)($optimized[$type] ?? ''));
                    if ($candidate !== '') $prompts[$type] = $guardedPrompt . ' Additional scene guidance: ' . $candidate;
                }
                $imageEngine = 'openai';
                $providerUsed = 'claude_optimized';
            } catch (Throwable $e) {
                $results = [];
                foreach ($imageTypes as $imageType) {
                    $id = ai_studio_insert_staging_row($db, $resolvedProductId, $imageType, 'claude_optimized', null, null, 'failed', $e->getMessage(), $targetChannel);
                    $results[] = ['image_type' => $imageType, 'status' => 'error', 'staging_id' => $id, 'error' => $e->getMessage()];
                }
                return ['success' => false, 'product_id' => $resolvedProductId, 'product_ref' => $productReference, 'provider' => $provider, 'target_channel' => $targetChannel, 'results' => $results, 'error' => 'Falha ao otimizar prompts com Claude: ' . $e->getMessage()];
            }
        }

        $openAiModel = ($modelOverride !== null && trim($modelOverride) !== '' && $imageEngine === 'openai') ? trim($modelOverride) : AI_STUDIO_OPENAI_IMAGE_MODEL;
        $googleModel = ($modelOverride !== null && trim($modelOverride) !== '' && $imageEngine === 'google') ? trim($modelOverride) : AI_STUDIO_GOOGLE_IMAGEN_MODEL;

        $results = [];
        foreach ($imageTypes as $imageType) {
            $prompt = $prompts[$imageType];
            $filename = ai_studio_unique_filename($resolvedProductId, $imageType);
            $destination = AI_STUDIO_STORAGE_DIR . $filename;
            $publicPath = AI_STUDIO_STORAGE_URL_PREFIX . $filename;

            try {
                if ($imageEngine === 'openai') {
                    (new AiStudioOpenAiClient(AI_STUDIO_OPENAI_API_KEY, $openAiModel))->editImageToFile($prompt, $baseImagePath, $destination);
                } else {
                    (new AiStudioGoogleImageEditClient(AI_STUDIO_GOOGLE_IMAGEN_API_KEY, $googleModel))->editImageToFile($prompt, $baseImagePath, $destination);
                }
                $quality = ai_studio_validate_image_file($destination, $minimumSide);
                $quality['recommended_side'] = $recommendedSide;
                $quality['meets_recommended_side'] = $quality['width'] >= $recommendedSide && $quality['height'] >= $recommendedSide;
                $id = ai_studio_insert_staging_row($db, $resolvedProductId, $imageType, $providerUsed, $publicPath, $prompt, 'pending', null, $targetChannel);
                $results[] = [
                    'image_type' => $imageType,
                    'status' => 'pending',
                    'staging_id' => $id,
                    'local_path' => $publicPath,
                    'target_channel' => $targetChannel,
                    'quality' => $quality,
                ];
            } catch (Throwable $e) {
                @unlink($destination);
                $id = ai_studio_insert_staging_row($db, $resolvedProductId, $imageType, $providerUsed, null, $prompt, 'failed', $e->getMessage(), $targetChannel);
                $results[] = ['image_type' => $imageType, 'status' => 'error', 'staging_id' => $id, 'target_channel' => $targetChannel, 'error' => $e->getMessage()];
                error_log("[ai-image-studio] produto #{$resolvedProductId} ref={$productReference} canal={$targetChannel} tipo={$imageType} provider={$imageEngine}: " . $e->getMessage());
            }
        }

        $anySuccess = array_reduce($results, static fn(bool $carry, array $row): bool => $carry || ($row['status'] ?? '') === 'pending', false);
        return ['success' => $anySuccess, 'product_id' => $resolvedProductId, 'product_ref' => $productReference, 'provider' => $provider, 'target_channel' => $targetChannel, 'results' => $results];
    } finally {
        if ($baseImageIsTemp && is_string($baseImagePath) && is_file($baseImagePath)) @unlink($baseImagePath);
    }
}

if (PHP_SAPI === 'cli' && realpath($argv[0] ?? '') === realpath(__FILE__)) {
    $productId = trim((string)($argv[1] ?? ''));
    $provider = (string)($argv[2] ?? '');
    $typesArg = (string)($argv[3] ?? '');
    $model = trim((string)($argv[4] ?? ''));
    $targetChannel = strtolower(trim((string)($argv[5] ?? 'site')));
    if ($productId === '' || $provider === '') {
        fwrite(STDERR, "Uso: php process_item.php <product_id> <openai|google|claude> [white,hero,ambient] [modelo] [site|ml|shopee|amazon|tiktok]\n");
        exit(1);
    }
    $types = $typesArg !== '' ? array_map('trim', explode(',', $typesArg)) : ['white', 'hero', 'ambient'];
    $db = ai_studio_db();
    if (!$db instanceof PDO) {
        fwrite(STDERR, "Falha ao conectar ao banco de dados.\n");
        exit(1);
    }
    $result = ai_studio_process_item($db, $productId, $provider, $types, $model !== '' ? $model : null, $targetChannel);
    fwrite(STDOUT, json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n");
    exit(($result['success'] ?? false) ? 0 : 1);
}

if (PHP_SAPI !== 'cli' && basename($_SERVER['SCRIPT_FILENAME'] ?? '') === basename(__FILE__)) {
    require_once __DIR__ . '/../../includes/admin-guard.php';
    header('Content-Type: application/json; charset=UTF-8');
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        http_response_code(405);
        echo json_encode(['success' => false, 'error' => 'Use POST.']);
        exit;
    }

    $productId = trim((string)($_POST['product_id'] ?? ''));
    $provider = (string)($_POST['provider'] ?? '');
    $rawTypes = $_POST['image_types'] ?? ['white', 'hero', 'ambient'];
    $types = is_array($rawTypes) ? array_map('strval', $rawTypes) : ['white', 'hero', 'ambient'];
    $model = trim((string)($_POST['model'] ?? ''));
    $targetChannel = strtolower(trim((string)($_POST['target_channel'] ?? 'site')));
    if ($productId === '' || $provider === '') {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'product_id e provider sao obrigatorios.']);
        exit;
    }

    $db = ai_studio_db();
    if (!$db instanceof PDO) {
        http_response_code(503);
        echo json_encode(['success' => false, 'error' => 'Banco de dados temporariamente indisponivel.']);
        exit;
    }

    echo json_encode(ai_studio_process_item($db, $productId, $provider, $types, $model !== '' ? $model : null, $targetChannel), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

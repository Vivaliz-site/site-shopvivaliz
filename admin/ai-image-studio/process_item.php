<?php

declare(strict_types=1);

/**
 * AI Image Studio - processamento de uma imagem por produto.
 *
 * Regra central: nunca gerar o produto do zero. Toda imagem nasce de uma
 * foto real cadastrada para o MESMO product_id e e tratada como staging ate
 * revisao humana. A rotina valida arquivo, MIME e resolucao antes e depois da
 * edicao por IA.
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/src/AiServices.php';

/** @return array<string,string> */
function ai_studio_default_prompts(string $productName): array
{
    $identity = "Use the supplied real photo of {$productName} as the only product reference. Preserve the exact product identity: shape, proportions, color, material appearance, labels, logos, printed text, connectors, controls and included parts. Do not invent, remove, replace or redesign any product feature. Do not add accessories that could be interpreted as included with the product.";

    return [
        'white' => $identity . ' Create a marketplace-safe main image: pure white RGB 255,255,255 background, product centered, fully visible and occupying about 85-95% of the frame, neutral professional lighting, natural contact shadow only, no badges, borders, promotional text, watermarks or extra objects. Square 1:1 composition, photorealistic and at least 1024x1024.',
        'hero' => $identity . ' Create a premium ecommerce hero image with controlled studio lighting and a clean neutral setting. Keep the product unobstructed and dominant in frame. No promotional text, badges or invented props. Square 1:1 composition, photorealistic and at least 1024x1024.',
        'ambient' => $identity . ' Place the exact product in a realistic usage context supported by the visible product category, without implying unsupported compatibility or accessories. Keep the product fully recognizable and unobstructed. Natural scale, perspective, shadows and lighting. Square 1:1 composition, photorealistic and at least 1024x1024.',
    ];
}

/** @return array{name:string,description:string,image_ref:string,sku:string,olist_id:string}|null */
function ai_studio_fetch_product(PDO $db, int $productId): ?array
{
    $stmt = $db->prepare('SELECT * FROM products WHERE id = ? LIMIT 1');
    $stmt->execute([$productId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!is_array($row)) {
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

/** @return array{width:int,height:int,mime:string,sha256:string} */
function ai_studio_validate_image_file(string $path, int $minimumSide = 600): array
{
    if (!is_file($path) || !is_readable($path) || (int)filesize($path) <= 0) {
        throw new AiStudioApiException('Arquivo de imagem inexistente, vazio ou ilegível.');
    }

    $info = @getimagesize($path);
    if (!is_array($info)) {
        throw new AiStudioApiException('O arquivo informado não é uma imagem válida.');
    }

    $width = (int)($info[0] ?? 0);
    $height = (int)($info[1] ?? 0);
    $mime = strtolower(trim((string)($info['mime'] ?? '')));
    if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp'], true)) {
        throw new AiStudioApiException("Formato real da imagem não permitido: {$mime}.");
    }
    if ($width < $minimumSide || $height < $minimumSide) {
        throw new AiStudioApiException("Imagem abaixo da resolução mínima de qualidade: {$width}x{$height}; mínimo {$minimumSide}px por lado.");
    }

    $hash = hash_file('sha256', $path);
    if (!is_string($hash) || $hash === '') {
        throw new AiStudioApiException('Não foi possível calcular a identidade do arquivo de imagem.');
    }

    return ['width' => $width, 'height' => $height, 'mime' => $mime, 'sha256' => $hash];
}

function ai_studio_resolve_base_image(string $imageRef, string $projectRoot, int $productId): string
{
    $imageRef = trim($imageRef);
    if ($imageRef === '') {
        throw new AiStudioApiException(
            "Produto #{$productId} não tem foto cadastrada. Não é possível gerar uma nova imagem sem referência real do produto."
        );
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
        throw new AiStudioApiException("Produto #{$productId}: caminho local de imagem inválido.");
    }

    $root = rtrim($projectRoot, '/');
    $localPath = $root . '/' . $relative;
    if (!is_file($localPath) || !is_readable($localPath)) {
        throw new AiStudioApiException("Produto #{$productId}: foto cadastrada não foi encontrada no disco.");
    }
    ai_studio_validate_image_file($localPath, 600);
    return $localPath;
}

function ai_studio_unique_filename(int $productId, string $imageType, string $extension = 'png'): string
{
    $safeType = preg_replace('/[^a-z0-9_-]+/i', '-', $imageType) ?: 'image';
    $random = bin2hex(random_bytes(6));
    return sprintf('product-%d-%s-%s-%s.%s', $productId, $safeType, date('Ymd-His'), $random, $extension);
}

function ai_studio_insert_staging_row(
    PDO $db,
    int $productId,
    string $imageType,
    string $providerUsed,
    ?string $localPath,
    ?string $promptUsed,
    string $status,
    ?string $errorMessage = null
): int {
    $stmt = $db->prepare(
        'INSERT INTO product_images_staging '
        . '(product_id, image_type, provider_used, local_path, prompt_used, status, error_message, created_at, updated_at) '
        . 'VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), NOW())'
    );
    $stmt->execute([
        $productId,
        $imageType,
        $providerUsed,
        $localPath ?? '',
        $promptUsed,
        $status,
        $errorMessage,
    ]);
    return (int)$db->lastInsertId();
}

/** @return array<string,mixed> */
function ai_studio_process_item(
    PDO $db,
    int $productId,
    string $provider,
    array $imageTypes = ['white', 'hero', 'ambient'],
    ?string $modelOverride = null
): array {
    $provider = strtolower(trim($provider));
    if (!in_array($provider, ['openai', 'google', 'claude'], true)) {
        return ['success' => false, 'product_id' => $productId, 'provider' => $provider, 'results' => [], 'error' => "Provider inválido: '{$provider}'."];
    }

    $imageTypes = array_values(array_unique(array_intersect(array_map('strval', $imageTypes), ['white', 'hero', 'ambient'])));
    if ($imageTypes === []) {
        return ['success' => false, 'product_id' => $productId, 'provider' => $provider, 'results' => [], 'error' => 'Nenhum tipo de imagem válido selecionado.'];
    }

    $product = ai_studio_fetch_product($db, $productId);
    if ($product === null) {
        return ['success' => false, 'product_id' => $productId, 'provider' => $provider, 'results' => [], 'error' => "Produto #{$productId} não encontrado ou sem nome."];
    }

    $baseImagePath = null;
    $baseImageIsTemp = false;
    try {
        try {
            $baseImagePath = ai_studio_resolve_base_image($product['image_ref'], dirname(__DIR__, 2), $productId);
            $baseImageIsTemp = str_starts_with($baseImagePath, AI_STUDIO_BASE_IMAGE_TMP_DIR);
        } catch (Throwable $e) {
            $results = [];
            foreach ($imageTypes as $imageType) {
                $id = ai_studio_insert_staging_row(
                    $db,
                    $productId,
                    $imageType,
                    $provider === 'claude' ? 'claude_optimized' : $provider,
                    null,
                    null,
                    'failed',
                    $e->getMessage()
                );
                $results[] = ['image_type' => $imageType, 'status' => 'error', 'staging_id' => $id, 'error' => $e->getMessage()];
            }
            return ['success' => false, 'product_id' => $productId, 'provider' => $provider, 'results' => $results, 'error' => 'Foto base inválida: ' . $e->getMessage()];
        }

        $imageEngine = $provider;
        $providerUsed = $provider;
        $prompts = ai_studio_default_prompts($product['name']);

        if ($provider === 'claude') {
            try {
                $optimized = (new AiStudioClaudeClient(AI_STUDIO_CLAUDE_API_KEY, AI_STUDIO_CLAUDE_MODEL))
                    ->optimizePrompts($product['name'], $product['description']);
                foreach ($prompts as $type => $guardedPrompt) {
                    $candidate = trim((string)($optimized[$type] ?? ''));
                    if ($candidate !== '') {
                        // Reaplica a regra de fidelidade mesmo quando Claude reescreve o prompt.
                        $prompts[$type] = $guardedPrompt . ' Additional scene guidance: ' . $candidate;
                    }
                }
                $imageEngine = 'openai';
                $providerUsed = 'claude_optimized';
            } catch (Throwable $e) {
                $results = [];
                foreach ($imageTypes as $imageType) {
                    $id = ai_studio_insert_staging_row($db, $productId, $imageType, 'claude_optimized', null, null, 'failed', $e->getMessage());
                    $results[] = ['image_type' => $imageType, 'status' => 'error', 'staging_id' => $id, 'error' => $e->getMessage()];
                }
                return ['success' => false, 'product_id' => $productId, 'provider' => $provider, 'results' => $results, 'error' => 'Falha ao otimizar prompts com Claude: ' . $e->getMessage()];
            }
        }

        $openAiModel = ($modelOverride !== null && trim($modelOverride) !== '' && $imageEngine === 'openai')
            ? trim($modelOverride)
            : AI_STUDIO_OPENAI_IMAGE_MODEL;
        $googleModel = ($modelOverride !== null && trim($modelOverride) !== '' && $imageEngine === 'google')
            ? trim($modelOverride)
            : AI_STUDIO_GOOGLE_IMAGEN_MODEL;

        $results = [];
        foreach ($imageTypes as $imageType) {
            $prompt = $prompts[$imageType];
            $filename = ai_studio_unique_filename($productId, $imageType);
            $destination = AI_STUDIO_STORAGE_DIR . $filename;
            $publicPath = AI_STUDIO_STORAGE_URL_PREFIX . $filename;

            try {
                if ($imageEngine === 'openai') {
                    (new AiStudioOpenAiClient(AI_STUDIO_OPENAI_API_KEY, $openAiModel))->editImageToFile($prompt, $baseImagePath, $destination);
                } else {
                    (new AiStudioGoogleImageEditClient(AI_STUDIO_GOOGLE_IMAGEN_API_KEY, $googleModel))->editImageToFile($prompt, $baseImagePath, $destination);
                }

                // Arquivo inválido nunca entra em staging como pending. Como
                // a composição é quadrada, 1000px por lado atende também ao
                // patamar de zoom recomendado para imagem principal Amazon.
                $quality = ai_studio_validate_image_file($destination, 1000);
                $id = ai_studio_insert_staging_row($db, $productId, $imageType, $providerUsed, $publicPath, $prompt, 'pending');
                $results[] = [
                    'image_type' => $imageType,
                    'status' => 'pending',
                    'staging_id' => $id,
                    'local_path' => $publicPath,
                    'quality' => $quality,
                ];
            } catch (Throwable $e) {
                @unlink($destination);
                $id = ai_studio_insert_staging_row($db, $productId, $imageType, $providerUsed, null, $prompt, 'failed', $e->getMessage());
                $results[] = ['image_type' => $imageType, 'status' => 'error', 'staging_id' => $id, 'error' => $e->getMessage()];
                error_log("[ai-image-studio] produto #{$productId} tipo={$imageType} provider={$imageEngine}: " . $e->getMessage());
            }
        }

        $anySuccess = array_reduce($results, static fn(bool $carry, array $row): bool => $carry || ($row['status'] ?? '') === 'pending', false);
        return ['success' => $anySuccess, 'product_id' => $productId, 'provider' => $provider, 'results' => $results];
    } finally {
        if ($baseImageIsTemp && is_string($baseImagePath) && is_file($baseImagePath)) {
            @unlink($baseImagePath);
        }
    }
}

if (PHP_SAPI === 'cli' && realpath($argv[0] ?? '') === realpath(__FILE__)) {
    $productId = (int)($argv[1] ?? 0);
    $provider = (string)($argv[2] ?? '');
    $typesArg = (string)($argv[3] ?? '');
    $model = trim((string)($argv[4] ?? ''));
    if ($productId <= 0 || $provider === '') {
        fwrite(STDERR, "Uso: php process_item.php <product_id> <openai|google|claude> [white,hero,ambient] [modelo]\n");
        exit(1);
    }
    $types = $typesArg !== '' ? array_map('trim', explode(',', $typesArg)) : ['white', 'hero', 'ambient'];
    $db = ai_studio_db();
    if (!$db instanceof PDO) {
        fwrite(STDERR, "Falha ao conectar ao banco de dados.\n");
        exit(1);
    }
    $result = ai_studio_process_item($db, $productId, $provider, $types, $model !== '' ? $model : null);
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

    $productId = (int)($_POST['product_id'] ?? 0);
    $provider = (string)($_POST['provider'] ?? '');
    $rawTypes = $_POST['image_types'] ?? ['white', 'hero', 'ambient'];
    $types = is_array($rawTypes) ? array_map('strval', $rawTypes) : ['white', 'hero', 'ambient'];
    $model = trim((string)($_POST['model'] ?? ''));
    if ($productId <= 0 || $provider === '') {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'product_id e provider são obrigatórios.']);
        exit;
    }

    $db = ai_studio_db();
    if (!$db instanceof PDO) {
        http_response_code(503);
        echo json_encode(['success' => false, 'error' => 'Banco de dados temporariamente indisponível.']);
        exit;
    }

    echo json_encode(ai_studio_process_item($db, $productId, $provider, $types, $model !== '' ? $model : null), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

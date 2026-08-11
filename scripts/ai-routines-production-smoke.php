<?php

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only.\n");
    exit(2);
}

if ((string)getenv('AI_PRODUCTION_SMOKE_CONFIRM') !== 'RUN_ALL_PROVIDER_CHANNEL_SMOKES') {
    fwrite(STDERR, "Refusing to run without AI_PRODUCTION_SMOKE_CONFIRM.\n");
    exit(2);
}

$root = dirname(__DIR__);
require_once $root . '/admin/ai-image-studio/process_item.php';
require_once $root . '/admin/catalog-optimization/api/optimize_catalog.php';
$storefrontResolver = $root . '/includes/storefront-image-source.php';
if (is_file($storefrontResolver)) {
    require_once $storefrontResolver;
}

function smoke_sanitize(string $message): string
{
    $message = preg_replace('/\b(?:sk-(?:proj-)?|AIza|ant-api)[A-Za-z0-9_\-]{8,}\b/u', '[secret-redacted]', $message) ?? $message;
    $message = preg_replace('/Bearer\s+[A-Za-z0-9._\-]+/i', 'Bearer [secret-redacted]', $message) ?? $message;
    $message = preg_replace('/([?&](?:key|api_key|token|access_token)=)[^&\s]+/i', '$1[secret-redacted]', $message) ?? $message;
    $message = trim(preg_replace('/\s+/u', ' ', $message) ?? $message);
    return function_exists('mb_substr') ? mb_substr($message, 0, 420, 'UTF-8') : substr($message, 0, 420);
}

function smoke_arg(string $name): string
{
    global $argv;
    $prefix = '--' . $name . '=';
    foreach ($argv as $arg) {
        if (str_starts_with((string)$arg, $prefix)) {
            return substr((string)$arg, strlen($prefix));
        }
    }
    return '';
}

function smoke_image_sources(PDO $db, array $product, string $root): array
{
    $sources = [];
    $sku = trim((string)($product['sku'] ?? ''));
    if ($sku !== '') {
        try {
            $stmt = $db->prepare(
                "SELECT site_url, local_url, image_url, original_url_olist, original_url, position, is_primary, id "
                . "FROM olist_product_images "
                . "WHERE sku = ? AND (status IS NULL OR status = '' OR status NOT IN ('error','deleted','inactive')) "
                . "ORDER BY is_primary DESC, position ASC, id ASC LIMIT 16"
            );
            $stmt->execute([$sku]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
                $resolved = '';
                if (function_exists('svsis_resolve_image_url')) {
                    $resolved = trim((string)svsis_resolve_image_url($row, $root));
                } else {
                    foreach (['original_url_olist', 'image_url', 'original_url', 'site_url', 'local_url'] as $field) {
                        $value = trim((string)($row[$field] ?? ''));
                        if ($value === '') continue;
                        if (preg_match('~^https?://~i', $value) === 1) {
                            $resolved = $value;
                            break;
                        }
                        if (str_starts_with($value, '/') && is_file($root . $value)) {
                            $resolved = $value;
                            break;
                        }
                    }
                }
                if ($resolved !== '' && !in_array($resolved, $sources, true)) {
                    $sources[] = $resolved;
                }
            }
        } catch (Throwable $e) {
            // Legacy installations may not expose every optional gallery column.
        }
    }
    $legacy = trim((string)($product['image_ref'] ?? ''));
    if ($legacy !== '' && !in_array($legacy, $sources, true)) {
        $sources[] = $legacy;
    }
    return $sources;
}

function smoke_pick_product(PDO $db, string $root): array
{
    $stmt = $db->query(
        "SELECT id FROM products "
        . "WHERE COALESCE(NULLIF(TRIM(name), ''), '') <> '' "
        . "ORDER BY CASE WHEN COALESCE(NULLIF(TRIM(description), ''), '') <> '' THEN 0 ELSE 1 END, id ASC LIMIT 120"
    );
    $ids = $stmt ? array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN)) : [];
    $errors = [];
    foreach ($ids as $productId) {
        if ($productId <= 0) continue;
        $product = ai_studio_fetch_product($db, $productId);
        if ($product === null) continue;
        foreach (smoke_image_sources($db, $product, $root) as $source) {
            try {
                $path = ai_studio_resolve_base_image($source, $root, $productId);
                $isTemp = str_starts_with($path, AI_STUDIO_BASE_IMAGE_TMP_DIR);
                ai_studio_validate_image_file($path, 600);
                $product['image_ref'] = $source;
                return [
                    'product_id' => $productId,
                    'product' => $product,
                    'base_image_path' => $path,
                    'base_image_is_temp' => $isTemp,
                    'source_kind' => preg_match('~^https?://~i', $source) === 1 ? 'remote' : 'local',
                ];
            } catch (Throwable $e) {
                $errors[] = smoke_sanitize($e->getMessage());
            }
        }
    }
    throw new RuntimeException('Nenhum produto com foto real valida foi encontrado para a matriz. ' . ($errors !== [] ? end($errors) : ''));
}

function smoke_image_client(string $selector): array
{
    $openAiModel = (string)AI_STUDIO_OPENAI_IMAGE_MODEL;
    $googleModel = (string)AI_STUDIO_GOOGLE_IMAGEN_MODEL;
    $openRouterModel = trim((string)(getenv('OPENROUTER_IMAGE_MODEL') ?: ''));
    if ($openRouterModel === '') $openRouterModel = $openAiModel;
    $groqModel = trim((string)(getenv('GROQ_IMAGE_MODEL') ?: ''));
    if ($groqModel === '') $groqModel = defined('AI_STUDIO_GROQ_IMAGE_MODEL') ? (string)AI_STUDIO_GROQ_IMAGE_MODEL : $openAiModel;

    return match ($selector) {
        'openai' => [new AiStudioOpenAiClient(ai_studio_secret_pool('AI_STUDIO_OPENAI_API_KEY', ['OPENAI_API_KEY']), $openAiModel), 'openai'],
        'google' => [new AiStudioGoogleImageEditClient(ai_studio_secret_pool('AI_STUDIO_GOOGLE_IMAGEN_API_KEY', ['GOOGLE_IMAGEN_API_KEY', 'GEMINI_API_KEY', 'GOOGLE_GEMINI_API_KEY']), $googleModel), 'google'],
        'openrouter' => [new AiStudioOpenRouterImageClient(
            ai_studio_secret_pool('AI_STUDIO_OPENROUTER_API_KEY', ['OPENROUTER_API_KEY']),
            $openRouterModel,
            defined('AI_STUDIO_OPENROUTER_API_BASE_URL') ? (string)AI_STUDIO_OPENROUTER_API_BASE_URL : 'https://openrouter.ai/api/v1',
            [
                'HTTP-Referer' => defined('AI_STUDIO_OPENROUTER_HTTP_REFERER') ? (string)AI_STUDIO_OPENROUTER_HTTP_REFERER : 'https://shopvivaliz.com.br',
                'X-OpenRouter-Title' => defined('AI_STUDIO_OPENROUTER_APP_TITLE') ? (string)AI_STUDIO_OPENROUTER_APP_TITLE : 'ShopVivaliz',
            ]
        ), 'openrouter'],
        // Groq nao expoe endpoint de edicao/geracao de imagem (so texto/audio);
        // o dashboard real (process_item.php) ja trata isso como otimizador de
        // prompt e usa outro motor para o pixel. Deixe o teste explicito para
        // nao mascarar um 404 estrutural como se fosse uma falha de credencial.
        'groq' => throw new AiStudioApiException('Groq nao possui endpoint de edicao/geracao de imagem; use-o apenas como otimizador de prompt.'),
        default => throw new InvalidArgumentException('Seletor de imagem invalido: ' . $selector),
    };
}

function smoke_run_image(PDO $db, array $fixture, string $channel, string $selector): array
{
    $started = microtime(true);
    $productId = (int)$fixture['product_id'];
    $product = $fixture['product'];
    $prompts = ai_studio_default_prompts((string)$product['name'], $channel, $product);
    $prompt = (string)$prompts['white'];
    $providerUsed = $selector;
    $destination = '';
    $publicPath = null;
    $stagingId = 0;

    try {
        if ($selector === 'claude') {
            $claude = new AiStudioClaudeClient(
                ai_studio_secret_pool('AI_STUDIO_CLAUDE_API_KEY', ['CLAUDE_API_KEY', 'ANTHROPIC_API_KEY']),
                (string)AI_STUDIO_CLAUDE_MODEL
            );
            $optimized = $claude->optimizePrompts((string)$product['name'], (string)$product['description'], $product);
            $extra = trim((string)($optimized['white'] ?? ''));
            if ($extra !== '') $prompt .= ' Additional scene guidance: ' . $extra;
            [$client] = smoke_image_client('openai');
            $providerUsed = 'claude+openai';
        } else {
            [$client, $providerUsed] = smoke_image_client($selector);
        }

        $filename = ai_studio_unique_filename($productId, 'white');
        $destination = AI_STUDIO_STORAGE_DIR . $filename;
        $publicPath = AI_STUDIO_STORAGE_URL_PREFIX . $filename;
        $client->editImageToFile($prompt, (string)$fixture['base_image_path'], $destination);
        $quality = ai_studio_validate_image_file($destination, 1000);
        $stagingId = ai_studio_insert_staging_row(
            $db,
            $productId,
            'white',
            $providerUsed,
            $publicPath,
            $prompt,
            'pending',
            null,
            $channel
        );
        return [
            'kind' => 'image',
            'channel' => $channel,
            'provider_requested' => $selector,
            'provider_used' => $providerUsed,
            'status' => 'PASS',
            'staging_id' => $stagingId,
            'width' => (int)$quality['width'],
            'height' => (int)$quality['height'],
            'duration_ms' => (int)round((microtime(true) - $started) * 1000),
        ];
    } catch (Throwable $e) {
        if ($destination !== '' && is_file($destination)) @unlink($destination);
        try {
            $stagingId = ai_studio_insert_staging_row(
                $db,
                $productId,
                'white',
                $providerUsed,
                null,
                $prompt,
                'failed',
                smoke_sanitize($e->getMessage()),
                $channel
            );
        } catch (Throwable) {
            $stagingId = 0;
        }
        return [
            'kind' => 'image',
            'channel' => $channel,
            'provider_requested' => $selector,
            'provider_used' => $providerUsed,
            'status' => 'FAIL',
            'staging_id' => $stagingId,
            'error' => smoke_sanitize($e->getMessage()),
            'duration_ms' => (int)round((microtime(true) - $started) * 1000),
        ];
    }
}

function smoke_catalog_structure_errors(array $data): array
{
    $errors = [];
    foreach (['optimized_title', 'optimized_description', 'meta_title', 'meta_description'] as $key) {
        if (!isset($data[$key]) || !is_string($data[$key]) || trim($data[$key]) === '') {
            $errors[] = 'missing:' . $key;
        }
    }
    foreach (['bullet_points', 'seo_keywords', 'marketing_hooks'] as $key) {
        if (!array_key_exists($key, $data) || !is_array($data[$key])) {
            $errors[] = 'missing-list:' . $key;
            continue;
        }
        foreach ($data[$key] as $value) {
            if (!is_string($value) || trim($value) === '') {
                $errors[] = 'invalid-list:' . $key;
                break;
            }
        }
    }
    if ($errors === [] && preg_match('/(?:R\$|\bpre[cç]o\b|\bestoque\b|\bparcel(?:a|as|ado|amento)?\b|\bfrete\s+gr[aá]tis\b|\bcupom\b|\bdesconto\b)/iu', ai_catalog_text_blob($data)) === 1) {
        $errors[] = 'protected-commerce-field';
    }
    return $errors;
}

function smoke_run_catalog(PDO $db, int $productId, string $channel, string $provider): array
{
    $started = microtime(true);
    $stagingId = 0;
    try {
        $product = ai_catalog_fetch_product($db, $productId);
        if ($product === null) throw new RuntimeException('Produto nao encontrado para catalogo.');
        $data = catalog_ai_make_provider($provider)->complete(
            ai_catalog_build_system_prompt($channel),
            ai_catalog_build_user_prompt($product, $channel)
        );
        if (!is_array($data)) throw new RuntimeException('Resposta nao estruturada.');
        $structureErrors = smoke_catalog_structure_errors($data);
        if ($structureErrors !== []) throw new RuntimeException('Estrutura insegura/incompleta: ' . implode(',', $structureErrors));
        $quality = ai_catalog_quality_report($data, $channel, $product);
        $stagingId = ai_catalog_insert_staging_row($db, $productId, $channel, $provider, $data, 'pending', null, $quality);
        $soft = array_flip(ai_catalog_soft_quality_checks());
        $hardWarnings = [];
        $softWarnings = [];
        foreach ($quality['checks'] as $check => $ok) {
            if ($ok) continue;
            if (isset($soft[$check])) $softWarnings[] = (string)$check;
            else $hardWarnings[] = (string)$check;
        }
        return [
            'kind' => 'catalog',
            'channel' => $channel,
            'provider_requested' => $provider,
            'provider_used' => $provider,
            'status' => 'PASS',
            'staging_id' => $stagingId,
            'quality_score' => (int)$quality['score'],
            'hard_warning_count' => count($hardWarnings),
            'soft_warning_count' => count($softWarnings),
            'duration_ms' => (int)round((microtime(true) - $started) * 1000),
        ];
    } catch (Throwable $e) {
        try {
            $stagingId = ai_catalog_insert_staging_row(
                $db,
                $productId,
                $channel,
                $provider,
                [
                    'optimized_title' => '',
                    'optimized_description' => '',
                    'bullet_points' => [],
                    'seo_keywords' => [],
                    'marketing_hooks' => [],
                    'meta_title' => '',
                    'meta_description' => '',
                ],
                'failed',
                smoke_sanitize($e->getMessage())
            );
        } catch (Throwable) {
            $stagingId = 0;
        }
        return [
            'kind' => 'catalog',
            'channel' => $channel,
            'provider_requested' => $provider,
            'provider_used' => $provider,
            'status' => 'FAIL',
            'staging_id' => $stagingId,
            'error' => smoke_sanitize($e->getMessage()),
            'duration_ms' => (int)round((microtime(true) - $started) * 1000),
        ];
    }
}

$outputPath = smoke_arg('json-output');
$expectedSha = smoke_arg('expected-sha');
if ($expectedSha !== '') {
    $releaseShaPath = $root . '/.release-sha';
    $activeSha = is_file($releaseShaPath) ? trim((string)file_get_contents($releaseShaPath)) : '';
    if ($activeSha !== '' && !hash_equals($expectedSha, $activeSha)) {
        fwrite(STDERR, "Active release does not match expected SHA.\n");
        exit(3);
    }
}

$db = ai_studio_db();
if (!$db instanceof PDO) {
    fwrite(STDERR, "Database unavailable.\n");
    exit(4);
}
svcp_ensure_schema($db);

$fixture = smoke_pick_product($db, $root);
$productId = (int)$fixture['product_id'];
$imageChannels = ['site', 'ml', 'shopee', 'amazon', 'tiktok'];
$imageSelectors = ['openai', 'google', 'claude', 'openrouter', 'groq'];
$catalogChannels = ['ml', 'shopee', 'amazon', 'tiktok', 'site', 'erp'];
$catalogProviders = ['openai', 'gemini', 'claude', 'openrouter', 'groq'];

$results = [];
foreach ($imageChannels as $channel) {
    foreach ($imageSelectors as $selector) {
        $result = smoke_run_image($db, $fixture, $channel, $selector);
        $results[] = $result;
        fwrite(STDOUT, sprintf("image channel=%s provider=%s status=%s\n", $channel, $selector, $result['status']));
    }
}

foreach ($catalogChannels as $channel) {
    foreach ($catalogProviders as $provider) {
        $result = smoke_run_catalog($db, $productId, $channel, $provider);
        $results[] = $result;
        fwrite(STDOUT, sprintf("catalog channel=%s provider=%s status=%s\n", $channel, $provider, $result['status']));
    }
}

if (!empty($fixture['base_image_is_temp']) && is_file((string)$fixture['base_image_path'])) {
    @unlink((string)$fixture['base_image_path']);
}

$pass = count(array_filter($results, static fn(array $row): bool => ($row['status'] ?? '') === 'PASS'));
$fail = count($results) - $pass;
$report = [
    'schema' => 'shopvivaliz.ai-smoke-matrix.v1',
    'generated_at' => gmdate(DATE_ATOM),
    'release_sha' => $expectedSha,
    'product_id' => $productId,
    'image_source_kind' => (string)$fixture['source_kind'],
    'expected_total' => 55,
    'attempted_total' => count($results),
    'pass_total' => $pass,
    'fail_total' => $fail,
    'all_combinations_attempted' => count($results) === 55,
    'publication_performed' => false,
    'results' => $results,
];

$json = json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
if (!is_string($json)) {
    fwrite(STDERR, "Failed to encode report.\n");
    exit(5);
}
if ($outputPath !== '') {
    $dir = dirname($outputPath);
    if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
        fwrite(STDERR, "Failed to create output directory.\n");
        exit(5);
    }
    file_put_contents($outputPath, $json . PHP_EOL, LOCK_EX);
}

fwrite(STDOUT, sprintf(
    "matrix attempted=%d pass=%d fail=%d publication=false product_id=%d\n",
    count($results),
    $pass,
    $fail,
    $productId
));

exit(count($results) === 55 ? 0 : 6);

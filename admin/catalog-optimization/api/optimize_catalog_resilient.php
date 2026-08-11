<?php

declare(strict_types=1);

require_once __DIR__ . '/../../../includes/admin-guard.php';
require_once __DIR__ . '/optimize_catalog.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Use POST.']);
    exit;
}

/** @return list<string> */
function catalog_resilient_structure_errors(array $data): array
{
    $errors = [];
    foreach (['optimized_title', 'optimized_description', 'meta_title', 'meta_description'] as $key) {
        if (!array_key_exists($key, $data) || !is_string($data[$key]) || trim($data[$key]) === '') {
            $errors[] = "campo obrigatorio ausente: {$key}";
        }
    }
    foreach (['bullet_points', 'seo_keywords', 'marketing_hooks'] as $key) {
        if (!array_key_exists($key, $data) || !is_array($data[$key])) {
            $errors[] = "lista obrigatoria ausente: {$key}";
            continue;
        }
        foreach ($data[$key] as $value) {
            if (!is_string($value) || trim($value) === '') {
                $errors[] = "valor invalido em {$key}";
                break;
            }
        }
    }

    if ($errors === [] && preg_match('/(?:R\$|\bpre[cç]o\b|\bestoque\b|\bparcel(?:a|as|ado|amento)?\b|\bfrete\s+gr[aá]tis\b|\bcupom\b|\bdesconto\b)/iu', ai_catalog_text_blob($data)) === 1) {
        $errors[] = 'a IA tentou incluir preco, estoque ou condicao comercial protegida';
    }
    return $errors;
}

$rawBody = file_get_contents('php://input') ?: '';
$json = json_decode($rawBody, true);
$input = is_array($json) ? $json : $_POST;

$productId = (int)($input['product_id'] ?? 0);
$channel = strtolower(trim((string)($input['target_channel'] ?? $input['channel'] ?? '')));
$provider = catalog_ai_normalize_provider((string)($input['provider'] ?? ''));

if ($productId <= 0 || $channel === '' || $provider === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'product_id, target_channel e provider sao obrigatorios.']);
    exit;
}
if (!array_key_exists($channel, catalog_ai_channels())) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Canal invalido.']);
    exit;
}
if (!in_array($provider, ai_catalog_allowed_providers(), true)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Provedor invalido.']);
    exit;
}

$db = catalog_ai_db();
if (!$db instanceof PDO) {
    http_response_code(503);
    echo json_encode(['success' => false, 'error' => 'Banco de dados temporariamente indisponivel.']);
    exit;
}

$product = ai_catalog_fetch_product($db, $productId);
if ($product === null) {
    http_response_code(404);
    echo json_encode(['success' => false, 'error' => "Produto #{$productId} nao encontrado ou sem nome."]);
    exit;
}

$providers = ai_catalog_provider_candidates($provider);
if ($providers === []) {
    http_response_code(503);
    echo json_encode(['success' => false, 'error' => 'Nenhum provedor de texto possui chave ativa disponivel.']);
    exit;
}

$lastError = 'Nenhum provedor conseguiu gerar um rascunho valido.';
foreach ($providers as $resolvedProvider) {
    if (!ai_catalog_provider_available($resolvedProvider)) {
        ai_catalog_provider_audit_log($resolvedProvider, $channel, 'skip', 'cooldown', $productId);
        continue;
    }

    try {
        $userPrompt = ai_catalog_build_user_prompt($product, $channel);
        $data = catalog_ai_make_provider($resolvedProvider)->complete(
            ai_catalog_build_system_prompt($channel),
            $userPrompt
        );
        $structureErrors = is_array($data) ? catalog_resilient_structure_errors($data) : ['resposta nao retornou um objeto de catalogo'];

        if ($structureErrors !== []) {
            // Resposta truncada/incompleta e comum em modelos menores; uma
            // segunda tentativa no mesmo provedor, com os campos faltantes
            // explicitados, recupera a maioria dos casos sem gastar o
            // fallback para outro provedor.
            $retryPrompt = $userPrompt . "\n\nATENCAO: a tentativa anterior veio incompleta (" . implode(', ', $structureErrors)
                . "). Responda de novo com o JSON completo, garantindo TODAS as chaves obrigatorias preenchidas, mesmo que precise ser mais conciso no texto.";
            $data = catalog_ai_make_provider($resolvedProvider)->complete(
                ai_catalog_build_system_prompt($channel),
                $retryPrompt
            );
            $structureErrors = is_array($data) ? catalog_resilient_structure_errors($data) : ['resposta nao retornou um objeto de catalogo'];
        }

        if (!is_array($data)) {
            throw new RuntimeException('Resposta do provedor nao retornou um objeto de catalogo.');
        }
        if ($structureErrors !== []) {
            throw new RuntimeException('Resposta insegura/incompleta apos nova tentativa: ' . implode(', ', $structureErrors));
        }

        $quality = ai_catalog_quality_report($data, $channel, $product);
        $soft = array_flip(ai_catalog_soft_quality_checks());
        $hardWarnings = [];
        $softWarnings = [];
        foreach ($quality['checks'] as $check => $ok) {
            if ($ok) continue;
            if (isset($soft[$check])) $softWarnings[] = (string)$check;
            else $hardWarnings[] = (string)$check;
        }

        $stagingId = ai_catalog_insert_staging_row(
            $db,
            $productId,
            $channel,
            $resolvedProvider,
            $data,
            'pending',
            null,
            $quality
        );

        $status = $hardWarnings === [] ? 'generated' : 'generated_with_quality_warnings';
        ai_catalog_provider_audit_log($resolvedProvider, $channel, 'ok', $status, $productId);

        echo json_encode([
            'success' => true,
            'product_id' => $productId,
            'channel' => $channel,
            'provider_requested' => $provider,
            'provider_used' => $resolvedProvider,
            'staging_id' => $stagingId,
            'quality_score' => (int)$quality['score'],
            'needs_review' => $hardWarnings !== [] || $softWarnings !== [],
            'hard_warnings' => $hardWarnings,
            'soft_warnings' => $softWarnings,
            'message' => $hardWarnings === []
                ? 'Rascunho gerado e pronto para revisao.'
                : 'Rascunho gerado com alertas. Revise e corrija antes de publicar.',
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    } catch (Throwable $e) {
        $lastError = $e->getMessage();
        ai_catalog_provider_audit_log($resolvedProvider, $channel, 'fail', $lastError, $productId);
        error_log('[catalog-optimization] resiliente falhou produto #' . $productId . ' canal=' . $channel . ' provider=' . $resolvedProvider . ': ' . $lastError);
    }
}

try {
    $failedId = ai_catalog_insert_staging_row(
        $db,
        $productId,
        $channel,
        $providers[0] ?? $provider,
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
        $lastError
    );
} catch (Throwable) {
    $failedId = 0;
}

http_response_code(422);
echo json_encode([
    'success' => false,
    'product_id' => $productId,
    'channel' => $channel,
    'provider' => $provider,
    'staging_id' => $failedId,
    'error' => $lastError,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

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

const CATALOG_RESILIENT_MAX_QUALITY_REFINEMENTS = 3;

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

/**
 * Separa os checks em bloqueios reais e recomendações editoriais.
 *
 * @return array{hard:list<string>,soft:list<string>}
 */
function catalog_resilient_quality_warnings(array $quality): array
{
    $softChecks = array_flip(ai_catalog_soft_quality_checks());
    $hard = [];
    $soft = [];
    foreach ((array)($quality['checks'] ?? []) as $check => $ok) {
        if ($ok) continue;
        if (isset($softChecks[$check])) {
            $soft[] = (string)$check;
        } else {
            $hard[] = (string)$check;
        }
    }
    return ['hard' => $hard, 'soft' => $soft];
}

/**
 * Prefere primeiro menos bloqueios hard, depois menos alertas e por fim score.
 */
function catalog_resilient_quality_is_better(array $candidate, array $current): bool
{
    $candidateWarnings = catalog_resilient_quality_warnings($candidate);
    $currentWarnings = catalog_resilient_quality_warnings($current);
    if (count($candidateWarnings['hard']) !== count($currentWarnings['hard'])) {
        return count($candidateWarnings['hard']) < count($currentWarnings['hard']);
    }
    if (count($candidateWarnings['soft']) !== count($currentWarnings['soft'])) {
        return count($candidateWarnings['soft']) < count($currentWarnings['soft']);
    }
    return (int)($candidate['score'] ?? 0) > (int)($current['score'] ?? 0);
}

/**
 * Revisa automaticamente a saida ate ela ficar publicavel ou ate atingir o
 * limite controlado de tentativas. O usuario nunca deve receber uma falha hard
 * como tarefa editorial: se o provedor nao conseguir corrigir, o chamador
 * descarta essa saida e tenta o proximo provedor.
 *
 * @return array{data:array<string,mixed>,quality:array<string,mixed>,refined:bool,initial_score:int,attempts:int}
 */
function catalog_resilient_refine_quality(
    string $provider,
    string $channel,
    array $product,
    string $baseUserPrompt,
    array $data,
    array $quality
): array {
    $initialScore = (int)($quality['score'] ?? 0);
    $bestData = $data;
    $bestQuality = $quality;
    $refined = false;
    $attempts = 0;

    for ($attempt = 1; $attempt <= CATALOG_RESILIENT_MAX_QUALITY_REFINEMENTS; $attempt++) {
        $warnings = catalog_resilient_quality_warnings($bestQuality);
        $score = (int)($bestQuality['score'] ?? 0);
        if ($warnings['hard'] === [] && $score >= 85) {
            break;
        }

        $issues = array_values(array_unique(array_merge($warnings['hard'], $warnings['soft'])));
        $previous = json_encode($bestData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($previous)) {
            break;
        }

        $refinementPrompt = $baseUserPrompt
            . "\n\nREVISAO AUTOMATICA OBRIGATORIA {$attempt}/" . CATALOG_RESILIENT_MAX_QUALITY_REFINEMENTS
            . ": o rascunho atual recebeu score {$score}/100."
            . ($issues !== [] ? " Corrija especificamente estes checks: " . implode(', ', $issues) . '.' : '')
            . "\nREGRA DE SAIDA: nenhum check HARD pode permanecer reprovado. Se houver conflito entre estilo e fatos, preserve os fatos e ajuste a redacao/estrutura."
            . "\nMantenha somente fatos comprovados na origem. Nao invente atributos, beneficios, compatibilidade, garantia ou condicoes comerciais."
            . "\nResponda novamente com o JSON COMPLETO no mesmo contrato, corrigindo tamanho, identidade, repeticao, estrutura e politica do canal."
            . "\nRASCUNHO ATUAL PARA CORRIGIR:\n" . $previous;

        $attempts++;
        try {
            $candidate = catalog_ai_make_provider($provider)->complete(
                ai_catalog_build_system_prompt($channel),
                $refinementPrompt
            );
            if (!is_array($candidate) || catalog_resilient_structure_errors($candidate) !== []) {
                continue;
            }
            $candidateQuality = ai_catalog_quality_report($candidate, $channel, $product);
            if (catalog_resilient_quality_is_better($candidateQuality, $bestQuality)) {
                $bestData = $candidate;
                $bestQuality = $candidateQuality;
                $refined = true;
            }
        } catch (Throwable $e) {
            error_log('[catalog-optimization] revisao automatica falhou provider=' . $provider . ' canal=' . $channel . ' tentativa=' . $attempt . ': ' . $e->getMessage());
        }
    }

    return [
        'data' => $bestData,
        'quality' => $bestQuality,
        'refined' => $refined,
        'initial_score' => $initialScore,
        'attempts' => $attempts,
    ];
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

$lastError = 'Nenhum provedor conseguiu gerar um rascunho publicavel.';
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
        $refinement = catalog_resilient_refine_quality(
            $resolvedProvider,
            $channel,
            $product,
            $userPrompt,
            $data,
            $quality
        );
        $data = $refinement['data'];
        $quality = $refinement['quality'];
        $warningSets = catalog_resilient_quality_warnings($quality);
        $hardWarnings = $warningSets['hard'];
        $softWarnings = $warningSets['soft'];

        if ($hardWarnings !== []) {
            $lastError = 'Quality gate continua reprovado apos reparo automatico com ' . $resolvedProvider
                . ': ' . implode(', ', $hardWarnings);
            ai_catalog_provider_audit_log($resolvedProvider, $channel, 'fail', 'quality_gate_hard_failed', $productId);
            error_log('[catalog-optimization] descartando saida nao publicavel produto #' . $productId . ' canal=' . $channel . ' provider=' . $resolvedProvider . ' checks=' . implode(',', $hardWarnings));
            continue;
        }

        // O mesmo validador usado antes da publicacao precisa aceitar a saida
        // antes que ela seja gravada como pending. Qualquer rejeicao aqui faz
        // fallback para o proximo provedor, em vez de criar trabalho manual.
        ai_catalog_validate_ai_response($data, $channel, $product);

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

        $status = $refinement['refined'] ? 'generated_after_auto_repair' : 'generated';
        ai_catalog_provider_audit_log($resolvedProvider, $channel, 'ok', $status, $productId);

        echo json_encode([
            'success' => true,
            'product_id' => $productId,
            'channel' => $channel,
            'provider_requested' => $provider,
            'provider_used' => $resolvedProvider,
            'staging_id' => $stagingId,
            'quality_score' => (int)$quality['score'],
            'quality_initial_score' => (int)$refinement['initial_score'],
            'quality_refined' => (bool)$refinement['refined'],
            'quality_refinement_attempts' => (int)$refinement['attempts'],
            'needs_review' => $softWarnings !== [],
            'hard_warnings' => [],
            'soft_warnings' => $softWarnings,
            'message' => $refinement['refined']
                ? 'Conteudo corrigido automaticamente pela IA, aprovado no quality gate e pronto para aprovacao.'
                : 'Conteudo aprovado no quality gate e pronto para aprovacao.',
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
    'message' => 'A geracao foi rejeitada automaticamente porque nenhum provedor conseguiu produzir conteudo publicavel. Nenhuma correcao manual e exigida para liberar uma saida invalida.',
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

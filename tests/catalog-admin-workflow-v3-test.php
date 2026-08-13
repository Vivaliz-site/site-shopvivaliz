<?php

declare(strict_types=1);

$root = dirname(__DIR__);

function sv_catalog_v3_assert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FALHOU: {$message}\n");
        exit(1);
    }
}

$guard = file_get_contents($root . '/includes/admin-guard.php');
$ui = file_get_contents($root . '/admin/assets/catalog-optimization-workflow.js');
$availabilityUi = file_get_contents($root . '/admin/assets/catalog-candidate-availability.js');
$candidateEndpoint = file_get_contents($root . '/admin/catalog-optimization/api/pending_candidates_unique.php');
$cleanup = file_get_contents($root . '/scripts/maintenance/cleanup-ai-smoke-staging.php');
$smokeWorkflow = file_get_contents($root . '/.github/workflows/ai-routines-production-smoke.yml');
$resilient = file_get_contents($root . '/admin/catalog-optimization/api/optimize_catalog_resilient.php');
$config = file_get_contents($root . '/admin/catalog-optimization/config_optimization.php');
$textServices = file_get_contents($root . '/admin/catalog-optimization/src/TextAiServices.php');
$normalizer = file_get_contents($root . '/admin/catalog-optimization/src/CatalogGeneratedDataNormalizer.php');
$repair = file_get_contents($root . '/admin/catalog-optimization/repair_hard_quality_pending.php');
$repairWorkflow = file_get_contents($root . '/.github/workflows/repair-catalog-hard-quality-pending.yml');

sv_catalog_v3_assert(is_string($guard) && $guard !== '', 'admin-guard.php precisa existir');
sv_catalog_v3_assert(is_string($ui) && $ui !== '', 'workflow unificado precisa existir');
sv_catalog_v3_assert(is_string($availabilityUi) && $availabilityUi !== '', 'diagnostico de disponibilidade precisa existir');
sv_catalog_v3_assert(is_string($candidateEndpoint) && $candidateEndpoint !== '', 'endpoint de candidatos precisa existir');
sv_catalog_v3_assert(is_string($cleanup) && $cleanup !== '', 'limpeza de staging de smoke precisa existir');
sv_catalog_v3_assert(is_string($smokeWorkflow) && $smokeWorkflow !== '', 'matriz de smoke precisa existir');
sv_catalog_v3_assert(is_string($resilient) && $resilient !== '', 'API resiliente precisa existir');
sv_catalog_v3_assert(is_string($config) && $config !== '', 'configuracao de IA do catalogo precisa existir');
sv_catalog_v3_assert(is_string($textServices) && $textServices !== '', 'servicos de provider precisam existir');
sv_catalog_v3_assert(is_string($normalizer) && $normalizer !== '', 'normalizador deterministico precisa existir');
sv_catalog_v3_assert(is_string($repair) && $repair !== '', 'reparo de pendencias hard precisa existir');
sv_catalog_v3_assert(is_string($repairWorkflow) && $repairWorkflow !== '', 'workflow de reparo pos-deploy precisa existir');

sv_catalog_v3_assert(
    str_contains($guard, 'catalog-optimization-workflow.js')
    && str_contains($guard, 'catalog-candidate-availability.js'),
    'Admin deve carregar o workflow unificado e o diagnostico de disponibilidade'
);
sv_catalog_v3_assert(
    !str_contains($guard, 'catalog-resilient-run-hotfix.js') && !str_contains($guard, 'catalog-candidate-race-guard.js'),
    'Admin nao deve empilhar os antigos listeners de hotfix do catalogo'
);
sv_catalog_v3_assert(
    str_contains($ui, '/admin/catalog-optimization/api/pending_candidates_unique.php'),
    'Selecao deve usar candidatos unicos'
);
sv_catalog_v3_assert(
    str_contains($ui, '/admin/catalog-optimization/api/optimize_catalog_resilient.php'),
    'Geracao deve usar a API resiliente'
);
sv_catalog_v3_assert(
    str_contains($ui, 'Otimizar selecionados')
    && str_contains($ui, 'Revisar e aplicar')
    && str_contains($ui, 'Pronto para aplicar'),
    'Fluxo visual precisa explicitar escolher, otimizar, revisar e aplicar'
);
sv_catalog_v3_assert(
    str_contains($ui, 'window.confirm') && str_contains($ui, 'confirm_channel'),
    'Aplicacao individual precisa manter confirmacao explicita do canal'
);
sv_catalog_v3_assert(
    str_contains($ui, 'bulk_publish') && str_contains($ui, 'bulk_confirm'),
    'Aplicacao em lote precisa preservar confirmacao explicita'
);
sv_catalog_v3_assert(
    str_contains($ui, 'sessionStorage.setItem') && str_contains($ui, 'scrollIntoView'),
    'Fluxo deve recuperar o ponto de revisao depois de salvar/aplicar/recarregar'
);
sv_catalog_v3_assert(
    str_contains($ui, 'selected: new Set()'),
    'Selecao deve persistir ao filtrar a lista sem depender apenas do DOM visivel'
);
sv_catalog_v3_assert(
    str_contains($ui, 'requestGeneration') && str_contains($ui, 'requestGeneration !== state.requestGeneration'),
    'Respostas antigas de candidatos nao podem sobrescrever o canal atual'
);
sv_catalog_v3_assert(
    str_contains($ui, "document.createElement('details')") && str_contains($ui, 'sv-regen-details'),
    'Regeneracao recolhida deve continuar acessivel por disclosure nativo'
);

sv_catalog_v3_assert(
    str_contains($candidateEndpoint, "'summary' => [")
    && str_contains($candidateEndpoint, "'active' =>")
    && str_contains($candidateEndpoint, "'eligible' =>")
    && str_contains($candidateEndpoint, "'in_review' =>"),
    'Endpoint deve separar produtos ativos, elegiveis e em revisao'
);
sv_catalog_v3_assert(
    str_contains($candidateEndpoint, 'cat_candidate_active_sql')
    && str_contains($candidateEndpoint, "NOT IN ('published','rejected','failed')"),
    'Elegibilidade deve tolerar schemas ativos e bloquear somente rascunhos operacionais'
);
sv_catalog_v3_assert(
    str_contains($availabilityUi, 'Todos os produtos deste canal já estão em revisão')
    && str_contains($availabilityUi, 'Ir para Revisar e aplicar')
    && str_contains($availabilityUi, 'in_review'),
    'Fila vazia deve explicar rascunhos existentes e levar para a revisao'
);
sv_catalog_v3_assert(
    str_contains($cleanup, "result['staging_id']")
    && str_contains($cleanup, "status = 'rejected'")
    && str_contains($cleanup, 'catalog_candidates_shopee_eligible='),
    'Limpeza deve usar IDs explicitos dos relatorios, retirar smoke da fila e diagnosticar Shopee'
);
sv_catalog_v3_assert(
    substr_count($smokeWorkflow, 'cleanup_smoke_staging') >= 4
    && str_contains($smokeWorkflow, "trap 'cleanup_smoke_staging || true' EXIT")
    && str_contains($smokeWorkflow, 'scripts/maintenance/cleanup-ai-smoke-staging.php'),
    'Matriz deve limpar staging tecnico antes, depois e no encerramento de seguranca'
);

sv_catalog_v3_assert(
    str_contains($normalizer, 'function catalog_generated_normalize(')
    && str_contains($normalizer, 'catalog_generated_identity_prefix')
    && str_contains($normalizer, 'catalog_generated_unsourced_claim_patterns')
    && str_contains($normalizer, 'catalog_generated_protected_commerce_pattern'),
    'Normalizador deve corrigir identidade, claims e campos comerciais por regra deterministica'
);
sv_catalog_v3_assert(
    str_contains($textServices, 'function catalog_ai_normalize_generated_data(')
    && str_contains($textServices, "__DIR__ . '/CatalogGeneratedDataNormalizer.php'")
    && str_contains($textServices, 'return catalog_ai_normalize_generated_data($data);'),
    'Toda saida real de provider deve passar pelo normalizador antes de voltar ao chamador'
);

sv_catalog_v3_assert(
    str_contains($resilient, 'catalog_resilient_refine_quality')
    && str_contains($resilient, 'quality_initial_score')
    && str_contains($resilient, 'quality_refined')
    && str_contains($resilient, 'quality_refinement_attempts'),
    'API deve reparar automaticamente a qualidade e reportar as tentativas'
);
sv_catalog_v3_assert(
    substr_count($resilient, 'catalog_resilient_refine_quality(') === 2,
    'Refinamento deve ter uma unica definicao e uma unica chamada no fluxo'
);
sv_catalog_v3_assert(
    str_contains($resilient, 'const CATALOG_RESILIENT_MAX_QUALITY_REFINEMENTS = 3;')
    && str_contains($resilient, 'for ($attempt = 1; $attempt <= CATALOG_RESILIENT_MAX_QUALITY_REFINEMENTS; $attempt++)'),
    'Falhas de qualidade devem receber ate tres revisoes automaticas controladas por provedor'
);
sv_catalog_v3_assert(
    str_contains($resilient, 'catalog_generated_normalize($data, $channel, $product)')
    && str_contains($resilient, 'catalog_generated_normalize($candidate, $channel, $product)'),
    'API resiliente deve normalizar primeira tentativa e refinamentos antes do gate'
);
sv_catalog_v3_assert(
    str_contains($resilient, "if (\$warnings['hard'] === [] && \$score >= 85)"),
    'Revisao automatica deve parar cedo somente quando nao houver hard failure e a qualidade ja for suficiente'
);
sv_catalog_v3_assert(
    str_contains($resilient, "if (\$hardWarnings !== [])")
    && str_contains($resilient, "continue;\n        }\n\n        // O mesmo validador usado antes da publicacao")
    && str_contains($resilient, 'ai_catalog_validate_ai_response($data, $channel, $product);'),
    'Saida com hard failure deve ser descartada e nunca chegar ao staging pending'
);
sv_catalog_v3_assert(
    !str_contains($resilient, 'generated_with_quality_warnings')
    && !str_contains($resilient, 'Revise e corrija antes de publicar.')
    && str_contains($resilient, "'hard_warnings' => []"),
    'API nao pode mais devolver success=true transferindo hard failure para correcao manual'
);
sv_catalog_v3_assert(
    str_contains($config, "catalog_ai_env_key_pool(['GOOGLE_GEMINI_API_KEY', 'GEMINI_API_KEY'])")
    && str_contains($config, "\$baseName . 'S'")
    && str_contains($config, 'for ($index = 1; $index <= 10; $index++)'),
    'Pool Gemini deve aceitar bundles, aliases e chaves numeradas para rotacao automatica'
);

sv_catalog_v3_assert(
    str_contains($repair, "s.status = 'pending'")
    && str_contains($repair, "s.status = 'failed'")
    && str_contains($repair, 'CATALOG_HARD_REPAIR_PREFIX')
    && str_contains($repair, "newer.status IN ('pending','published','rejected')")
    && str_contains($repair, 'catalog_hard_repair_resumed=')
    && str_contains($repair, 'catalog_hard_repair_generate(')
    && str_contains($repair, 'CATALOG_HARD_REPAIR_PROVIDER_ATTEMPTS = 2')
    && str_contains($repair, 'catalog_generated_normalize($data, $channel, $product)')
    && str_contains($repair, "SET status = 'failed'"),
    'Pendencias hard e reparos interrompidos devem ser retomados com normalizacao e nova tentativa guiada'
);
sv_catalog_v3_assert(
    str_contains($repair, 'catalog_hard_repair_publication_attempted=false')
    && !str_contains($repair, 'CatalogPublisher')
    && !str_contains($repair, 'publish('),
    'Reparo de qualidade nunca pode publicar em marketplace'
);
sv_catalog_v3_assert(
    str_contains($repairWorkflow, "workflows: ['Master Production Pipeline 24/7']")
    && str_contains($repairWorkflow, "github.event.workflow_run.conclusion == 'success'")
    && str_contains($repairWorkflow, 'workflow_dispatch:')
    && !str_contains($repairWorkflow, "\n  push:")
    && str_contains($repairWorkflow, 'TARGET_SHA:')
    && str_contains($repairWorkflow, 'production-catalog-hard-quality-repair-v2')
    && str_contains($repairWorkflow, 'merge-base --is-ancestor "$target" "$deployed"')
    && str_contains($repairWorkflow, "production_release_relation=\$relation")
    && str_contains($repairWorkflow, 'cancel-in-progress: false')
    && str_contains($repairWorkflow, 'ServerAliveInterval=30')
    && str_contains($repairWorkflow, 'ServerAliveCountMax=6')
    && str_contains($repairWorkflow, 'catalog_repair_interruption_resumable=true')
    && str_contains($repairWorkflow, 'catalog_repair_ssh_keepalive=true')
    && str_contains($repairWorkflow, 'catalog_repair_push_trigger=false')
    && str_contains($repairWorkflow, 'catalog_repair_accepts_deployed_descendant=true')
    && str_contains($repairWorkflow, 'php admin/catalog-optimization/repair_hard_quality_pending.php --limit=2000'),
    'Reparo pos-deploy deve ignorar pushes intermediarios, aceitar release descendente e manter fila v2 serial/retomavel'
);

fwrite(STDOUT, "COMPROVADO: candidatos ficam disponiveis, smokes nao bloqueiam a fila, hard failure nao vira trabalho manual e reparo legado nao fica preso em SHA intermediario.\n");

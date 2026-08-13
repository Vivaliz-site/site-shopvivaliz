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
$resilient = file_get_contents($root . '/admin/catalog-optimization/api/optimize_catalog_resilient.php');
$config = file_get_contents($root . '/admin/catalog-optimization/config_optimization.php');

sv_catalog_v3_assert(is_string($guard) && $guard !== '', 'admin-guard.php precisa existir');
sv_catalog_v3_assert(is_string($ui) && $ui !== '', 'workflow unificado precisa existir');
sv_catalog_v3_assert(is_string($resilient) && $resilient !== '', 'API resiliente precisa existir');
sv_catalog_v3_assert(is_string($config) && $config !== '', 'configuracao de IA do catalogo precisa existir');

sv_catalog_v3_assert(
    str_contains($guard, 'catalog-optimization-workflow.js'),
    'Admin deve carregar o workflow unificado do catalogo'
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

fwrite(STDOUT, "COMPROVADO: workflow do Admin usa selecao estavel, qualidade auto-reparada, rejeita hard failures antes do staging e preserva rotacao de credenciais Gemini.\n");

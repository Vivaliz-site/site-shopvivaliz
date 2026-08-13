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
$ui = file_get_contents($root . '/admin/assets/catalog-optimization-workflow-v3.js');
$resilient = file_get_contents($root . '/admin/catalog-optimization/api/optimize_catalog_resilient.php');

sv_catalog_v3_assert(is_string($guard) && $guard !== '', 'admin-guard.php precisa existir');
sv_catalog_v3_assert(is_string($ui) && $ui !== '', 'workflow v3 precisa existir');
sv_catalog_v3_assert(is_string($resilient) && $resilient !== '', 'API resiliente precisa existir');

sv_catalog_v3_assert(
    str_contains($guard, 'catalog-optimization-workflow-v3.js'),
    'Admin deve carregar o workflow unificado v3'
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
    str_contains($resilient, 'catalog_resilient_refine_quality')
    && str_contains($resilient, 'quality_initial_score')
    && str_contains($resilient, 'quality_refined'),
    'API deve fazer uma revisao de qualidade controlada e reportar o resultado'
);
sv_catalog_v3_assert(
    substr_count($resilient, 'catalog_resilient_refine_quality(') === 2,
    'Refinamento deve ter uma unica definicao e uma unica chamada no fluxo'
);
sv_catalog_v3_assert(
    str_contains($resilient, "if (\$warnings['hard'] === [] && \$initialScore >= 85)"),
    'Segunda chamada de IA deve acontecer apenas quando houver bloqueio ou score baixo'
);

fwrite(STDOUT, "COMPROVADO: workflow v3 do Admin de otimizacao possui selecao unica, progresso, revisao/aplicacao explicita e refinamento controlado.\n");

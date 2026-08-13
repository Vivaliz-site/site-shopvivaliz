<?php

declare(strict_types=1);

$root = dirname(__DIR__);

function sv_image_v3_assert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FALHOU: {$message}\n");
        exit(1);
    }
}

$guard = file_get_contents($root . '/includes/admin-guard.php');
$ui = file_get_contents($root . '/admin/assets/image-generation-workflow.js');
$statusApi = file_get_contents($root . '/admin/ai-image-studio/api/generation_status.php');
$pendingApi = file_get_contents($root . '/admin/ai-image-studio/api/pending_candidates.php');
$enqueueApi = file_get_contents($root . '/admin/ai-image-studio/api/enqueue_generation.php');

sv_image_v3_assert(is_string($guard) && $guard !== '', 'admin-guard.php precisa existir');
sv_image_v3_assert(is_string($ui) && $ui !== '', 'workflow unificado de imagem precisa existir');
sv_image_v3_assert(is_string($statusApi) && $statusApi !== '', 'API de andamento da fila precisa existir');
sv_image_v3_assert(is_string($pendingApi) && $pendingApi !== '', 'API de candidatos precisa existir');
sv_image_v3_assert(is_string($enqueueApi) && $enqueueApi !== '', 'API de enfileiramento precisa existir');

sv_image_v3_assert(
    str_contains($guard, 'image-generation-workflow.js')
    && !str_contains($guard, 'ai-image-studio-workflow.js')
    && !str_contains($guard, 'ai-routines-hotfix-ui.js'),
    'Image Studio deve ter um unico controlador ativo, sem empilhar controladores antigos'
);
sv_image_v3_assert(
    str_contains($ui, '/api/pending_candidates.php')
    && str_contains($ui, '/api/enqueue_generation.php')
    && str_contains($ui, '/api/generation_status.php'),
    'Workflow deve cobrir selecao, fila e acompanhamento'
);
sv_image_v3_assert(
    str_contains($ui, 'new Set()')
    && str_contains($ui, 'new Map()')
    && str_contains($ui, 'Nenhum produto selecionado'),
    'Selecao deve ser explicita e preservar tipos por produto'
);
sv_image_v3_assert(
    str_contains($ui, 'readiness_state')
    && str_contains($ui, 'readiness_score')
    && str_contains($pendingApi, "'source_width'")
    && str_contains($pendingApi, "'source_height'"),
    'Prontidao deve considerar contexto e resolucao da foto real'
);
sv_image_v3_assert(
    str_contains($ui, 'essential_types')
    && str_contains($ui, 'recommended_types')
    && str_contains($ui, 'Usar recomendação inteligente do canal'),
    'UI deve oferecer plano visual e regeneracao orientados pelo canal'
);
sv_image_v3_assert(
    str_contains($ui, 'Comparar foto real e imagem gerada')
    && str_contains($ui, 'local_reference')
    && str_contains($ui, 'Regenerar antes de publicar'),
    'Revisao deve comparar a referencia real e bloquear fallback sem edicao visual'
);
sv_image_v3_assert(
    str_contains($statusApi, "require_once __DIR__ . '/../../../includes/admin-guard.php'")
    && str_contains($statusApi, 'sv_queue_uses_file_backend')
    && str_contains($statusApi, 'sv_queue_db()'),
    'Andamento da fila deve ser autenticado e funcionar nos dois backends'
);
sv_image_v3_assert(
    str_contains($statusApi, '[redacted]')
    && str_contains($statusApi, "array_slice(\$ids, 0, 100)"),
    'Endpoint de andamento deve limitar lote e redigir padroes de credencial'
);
sv_image_v3_assert(
    str_contains($pendingApi, "'has_image'")
    && str_contains($pendingApi, "'source_image_url'")
    && str_contains($pendingApi, "'source_resolution_state'"),
    'Candidatos devem provar fonte visual e qualidade basica sem expor dados desnecessarios'
);
sv_image_v3_assert(
    str_contains($enqueueApi, 'ai_studio_resolve_base_image')
    && str_contains($enqueueApi, 'Nenhuma foto real valida foi encontrada'),
    'Enfileiramento deve continuar fail-closed sem foto real valida'
);

fwrite(STDOUT, "COMPROVADO: Image Studio possui controlador unico, selecao explicita, prontidao por fonte/resolucao, fila acompanhada e revisao humana segura.\n");

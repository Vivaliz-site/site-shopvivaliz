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
$ui = file_get_contents($root . '/admin/assets/ai-image-studio-workflow.js');
$statusApi = file_get_contents($root . '/admin/ai-image-studio/api/generation_status.php');
$pendingApi = file_get_contents($root . '/admin/ai-image-studio/api/pending_candidates.php');
$enqueueApi = file_get_contents($root . '/admin/ai-image-studio/api/enqueue_generation.php');

sv_image_v3_assert(is_string($guard) && $guard !== '', 'admin-guard.php precisa existir');
sv_image_v3_assert(is_string($ui) && $ui !== '', 'workflow unificado de imagem precisa existir');
sv_image_v3_assert(is_string($statusApi) && $statusApi !== '', 'API de andamento da fila precisa existir');
sv_image_v3_assert(is_string($pendingApi) && $pendingApi !== '', 'API de candidatos precisa existir');
sv_image_v3_assert(is_string($enqueueApi) && $enqueueApi !== '', 'API de enfileiramento precisa existir');

sv_image_v3_assert(
    str_contains($guard, 'ai-image-studio-workflow.js')
    && !str_contains($guard, 'ai-routines-hotfix-ui.js'),
    'Image Studio deve ter um unico controlador, sem empilhar o hotfix generico'
);
sv_image_v3_assert(
    str_contains($ui, '/admin/ai-image-studio/api/pending_candidates.php')
    && str_contains($ui, '/admin/ai-image-studio/api/enqueue_generation.php')
    && str_contains($ui, '/admin/ai-image-studio/api/generation_status.php'),
    'Workflow deve cobrir selecao, fila e acompanhamento'
);
sv_image_v3_assert(
    str_contains($ui, 'selected: new Set()')
    && str_contains($ui, 'types: new Map()')
    && str_contains($ui, 'requestGeneration'),
    'Selecao/tipos devem persistir ao filtrar e respostas antigas nao podem substituir o canal atual'
);
sv_image_v3_assert(
    str_contains($ui, 'Foto real OK')
    && str_contains($ui, 'Corrigir origem')
    && str_contains($ui, 'checkbox.disabled = !item.has_image'),
    'Produtos sem foto real valida devem ser diagnosticados e bloqueados da fila'
);
sv_image_v3_assert(
    str_contains($ui, 'OpenRouter usa a API de imagem com a foto real como referência')
    && str_contains($ui, 'Groq atua na instrução da cena')
    && str_contains($ui, 'Claude atua na instrução da cena'),
    'UI deve explicar claramente a capacidade real dos provedores'
);
sv_image_v3_assert(
    str_contains($ui, 'Compare produto, cor, forma, proporção, acessórios e composição')
    && str_contains($ui, 'window.confirm')
    && str_contains($ui, 'confirm_channel'),
    'Revisao deve exigir comparacao visual e confirmacao explicita antes de publicar'
);
sv_image_v3_assert(
    str_contains($ui, 'Sem edição visual de IA')
    && str_contains($ui, "publish.disabled = true")
    && str_contains($ui, 'local_reference'),
    'Fallback local nao pode parecer uma geracao concluida na interface'
);
sv_image_v3_assert(
    str_contains($ui, "document.createElement('details')")
    && str_contains($ui, 'sv-regen-details')
    && str_contains($ui, 'sv-tech-details'),
    'Regeneracao e detalhes tecnicos devem usar disclosures nativos acessiveis'
);
sv_image_v3_assert(
    str_contains($statusApi, 'require_once __DIR__ . \'/../../../includes/admin-guard.php\'')
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
    && str_contains($pendingApi, "'image_url'"),
    'Candidatos devem expor apenas o necessario para provar a fonte visual'
);
sv_image_v3_assert(
    str_contains($enqueueApi, 'ai_studio_resolve_base_image')
    && str_contains($enqueueApi, 'Nenhuma foto real valida foi encontrada'),
    'Enfileiramento deve continuar fail-closed sem foto real valida'
);

fwrite(STDOUT, "COMPROVADO: Image Studio possui fluxo unico de selecao, fila acompanhada, revisao humana explicita e bloqueio operacional de fonte invalida/fallback visual.\n");

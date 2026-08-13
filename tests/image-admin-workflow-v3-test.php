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
$safetyUi = file_get_contents($root . '/admin/assets/image-workflow-safety.js');
$healthUi = file_get_contents($root . '/admin/assets/image-provider-health.js');
$healthApi = file_get_contents($root . '/admin/ai-image-studio/api/provider_health_check.php');
$statusApi = file_get_contents($root . '/admin/ai-image-studio/api/generation_status.php');
$reviewSourcesApi = file_get_contents($root . '/admin/ai-image-studio/api/review_sources.php');
$pendingApi = file_get_contents($root . '/admin/ai-image-studio/api/pending_candidates.php');
$enqueueApi = file_get_contents($root . '/admin/ai-image-studio/api/enqueue_generation.php');
$schema = file_get_contents($root . '/includes/catalog-publication-schema.php');
$worker = file_get_contents($root . '/scripts/ai-image-studio-worker.php');

foreach (compact('guard', 'ui', 'safetyUi', 'healthUi', 'healthApi', 'statusApi', 'reviewSourcesApi', 'pendingApi', 'enqueueApi', 'schema', 'worker') as $name => $content) {
    sv_image_v3_assert(is_string($content) && $content !== '', "{$name} precisa existir e ter conteúdo");
}

sv_image_v3_assert(
    str_contains($guard, 'image-generation-workflow.js')
    && str_contains($guard, 'image-workflow-safety.js')
    && str_contains($guard, 'image-provider-health.js')
    && !str_contains($guard, 'ai-image-studio-workflow.js')
    && !str_contains($guard, 'ai-routines-hotfix-ui.js'),
    'Image Studio deve ter um unico controlador ativo, camada safety e complemento diagnostico'
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
    str_contains($pendingApi, 'ais_pending_gallery_sources')
    && str_contains($pendingApi, 'array_chunk($skus, 200)')
    && str_contains($pendingApi, "'query_strategy' => 'batched_products_and_gallery'")
    && !str_contains($pendingApi, 'ai_studio_fetch_product($db, $productId)'),
    'Lista ampla de candidatos deve usar consultas em lote, sem N+1 por produto'
);
sv_image_v3_assert(
    str_contains($ui, 'essential_types')
    && str_contains($ui, 'recommended_types')
    && str_contains($ui, 'Usar recomendação inteligente do canal'),
    'UI deve oferecer plano visual e regeneracao orientados pelo canal'
);
sv_image_v3_assert(
    str_contains($healthUi, 'Validar IAs')
    && str_contains($healthUi, '/api/provider_health_check.php')
    && str_contains($healthUi, 'working_key_count'),
    'Dashboard deve oferecer preflight explicito dos provedores sem gerar imagem'
);
sv_image_v3_assert(
    str_contains($healthApi, 'ais_health_probe_pool')
    && str_contains($healthApi, 'working_key_count')
    && str_contains($healthApi, "foreach (['claude', 'groq'] as \$optimizer)")
    && str_contains($healthApi, "'has_visual_editor' => \$hasEditor"),
    'Preflight deve validar o pool ate encontrar chave util e exigir editor visual para Claude/Groq'
);
sv_image_v3_assert(
    str_contains($healthApi, 'ais_health_sanitize')
    && str_contains($healthApi, 'Bearer [redacted]')
    && str_contains($healthApi, 'refresh_token'),
    'Preflight nao pode devolver segredo em erro de chave, rede ou capacidade'
);
sv_image_v3_assert(
    str_contains($ui, 'Comparar foto real e imagem gerada')
    && str_contains($ui, 'local_reference')
    && str_contains($ui, 'Regenerar antes de publicar'),
    'Revisao deve comparar a referencia real e bloquear fallback sem edicao visual'
);
sv_image_v3_assert(
    str_contains($ui, 'Confirmar identidade, cor, forma, proporção e acessórios')
    && !str_contains($ui, '>Identidade preservada<'),
    'Identidade visual deve ser requisito de revisao humana, nao afirmacao automatica'
);
sv_image_v3_assert(
    str_contains($safetyUi, 'Confirme explicitamente a revisão visual')
    && str_contains($safetyUi, 'A confirmação não é preenchida automaticamente')
    && str_contains($safetyUi, 'iv-bulk-visual-review')
    && str_contains($safetyUi, "event.stopImmediatePropagation()"),
    'Publicacao individual e em lote deve exigir confirmacao visual humana explicita antes do handler de envio'
);
sv_image_v3_assert(
    str_contains($safetyUi, "box.addEventListener('change', invalidate)")
    && str_contains($safetyUi, 'checkbox.checked = false'),
    'Confirmacao visual em lote deve ser invalidada sempre que a selecao mudar'
);
sv_image_v3_assert(
    str_contains($safetyUi, "sessionStorage.setItem('ivModel'")
    && str_contains($safetyUi, "event.target.id !== 'iv-provider'"),
    'Troca de provedor deve persistir o modelo compativel atualizado'
);
sv_image_v3_assert(
    !str_contains($ui, 'Groq — prompt + editor visual')
    && str_contains($ui, 'option[value="groq"]'),
    'Regeneracao nao deve oferecer Groq quando o formulario servidor nao conclui pixels com ele'
);
sv_image_v3_assert(
    str_contains($statusApi, "require_once __DIR__ . '/../../../includes/admin-guard.php'")
    && str_contains($statusApi, 'sv_queue_uses_file_backend')
    && str_contains($statusApi, 'sv_queue_db()'),
    'Andamento da fila deve ser autenticado e funcionar nos dois backends'
);
sv_image_v3_assert(
    str_contains($statusApi, '[redacted]')
    && str_contains($statusApi, "array_slice(\$jobIds, 0, 100)")
    && str_contains($statusApi, "'limit' => 100")
    && str_contains($statusApi, "'truncated'"),
    'Endpoint de andamento deve limitar lote, sinalizar truncamento e redigir padroes de credencial'
);
sv_image_v3_assert(
    str_contains($safetyUi, 'offset += 100')
    && str_contains($safetyUi, "chunks: chunks.length")
    && str_contains($safetyUi, 'returned_job_count: jobs.length'),
    'Dashboard deve paginar polling acima de 100 jobs e recompor todos os resultados'
);
sv_image_v3_assert(
    str_contains($safetyUi, 'const trackedJobs = new Map()')
    && str_contains($safetyUi, 'setInterval(async () =>')
    && str_contains($safetyUi, 'trackedJobs.clear()'),
    'Monitor de longa duracao deve continuar consultando jobs ate todos terminarem'
);
sv_image_v3_assert(
    str_contains($statusApi, 'Bearer [redacted]')
    && str_contains($statusApi, 'refresh_token'),
    'Redacao deve cobrir headers Bearer e tokens em mensagens de erro'
);
sv_image_v3_assert(
    str_contains($statusApi, "'partial_failure'")
    && str_contains($statusApi, "'missing' => \$missingTypes")
    && str_contains($ui, "job.result_state==='partial_failure'"),
    'Falha parcial de variantes deve aparecer explicitamente no backend e na UI'
);
sv_image_v3_assert(
    str_contains($statusApi, 'WHERE source_job_id = ? AND product_id = ?')
    && str_contains($schema, "'source_job_id'")
    && str_contains($worker, 'SET source_job_id = ?')
    && str_contains($worker, 'beginTransaction()')
    && str_contains($worker, 'commit()')
    && str_contains($worker, 'correlacionar atomicamente todos os resultados e a referencia visual ao job da fila'),
    'Staging deve ser correlacionado ao job exato dentro da mesma transacao para nao criar resultados publicaveis orfaos'
);
sv_image_v3_assert(
    str_contains($schema, "'source_image_ref'")
    && str_contains($worker, '$sourceImageRef')
    && str_contains($worker, 'source_image_ref = ?')
    && str_contains($reviewSourcesApi, 'source_image_ref')
    && str_contains($reviewSourcesApi, "'source_auditable' => \$source !== ''")
    && str_contains($safetyUi, '/api/review_sources.php')
    && str_contains($safetyUi, 'before.src = item.source_image_ref')
    && str_contains($safetyUi, 'Antes — foto real usada na geração'),
    'Revisao deve persistir e exibir exatamente a foto de referencia fornecida ao modelo'
);
sv_image_v3_assert(
    str_contains($safetyUi, 'blockUnverifiableSource')
    && str_contains($safetyUi, 'dataset.sourceAuditable')
    && str_contains($safetyUi, 'a regeneração agora começa no dashboard'),
    'Referencia ausente deve bloquear publicacao e regeneracao antiga deve voltar ao fluxo auditavel do dashboard'
);
sv_image_v3_assert(
    str_contains($worker, '$db = ai_studio_db();')
    && str_contains($worker, 'if (!$db instanceof PDO)')
    && str_contains($worker, 'rollBack()'),
    'Worker de longa duracao deve renovar a conexao antes de cada job e reverter staging incompleto em erro'
);
sv_image_v3_assert(
    str_contains($statusApi, "throw new RuntimeException('Backend de fila indisponivel.'")
    && str_contains($statusApi, "throw new RuntimeException('Staging de imagens indisponivel.'"),
    'Falha de backend nao pode ser convertida silenciosamente em job desconhecido'
);
sv_image_v3_assert(
    str_contains($pendingApi, "'has_image'")
    && str_contains($pendingApi, "'source_image_url'")
    && str_contains($pendingApi, "'source_resolution_state'"),
    'Candidatos devem provar fonte visual e qualidade basica sem expor dados desnecessarios'
);
sv_image_v3_assert(
    str_contains($enqueueApi, 'ais_enqueue_request_signature')
    && str_contains($enqueueApi, 'hash_equals($requestedSignature')
    && str_contains($enqueueApi, 'GET_LOCK(?, 10)')
    && str_contains($enqueueApi, 'RELEASE_LOCK(?)')
    && !str_contains($enqueueApi, 'LIMIT 250')
    && str_contains($enqueueApi, 'ai_studio_resolve_base_image')
    && str_contains($enqueueApi, 'Nenhuma foto real valida foi encontrada'),
    'Enfileiramento deve deduplicar todos os jobs ativos equivalentes sob lock atomico e continuar fail-closed sem foto real valida'
);
sv_image_v3_assert(
    str_contains($enqueueApi, 'cover_normalized_to_white')
    && str_contains($safetyUi, 'STRICT_WHITE_CHANNELS')
    && str_contains($safetyUi, "type === 'cover'"),
    'Canais white-first devem normalizar novas capas para white e bloquear covers legadas na publicacao'
);

fwrite(STDOUT, "COMPROVADO: Image Studio possui fila auditavel, referencia visual persistida, polling duravel e confirmacao humana.\n");

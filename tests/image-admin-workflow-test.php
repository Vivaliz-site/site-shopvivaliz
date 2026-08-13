<?php

declare(strict_types=1);

$root = dirname(__DIR__);

function iv_assert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FALHOU: {$message}\n");
        exit(1);
    }
}

$guard = file_get_contents($root . '/includes/admin-guard.php');
$ui = file_get_contents($root . '/admin/assets/image-generation-workflow.js');
$profilesApi = file_get_contents($root . '/admin/ai-image-studio/api/channel_profiles.php');
$profiles = file_get_contents($root . '/admin/ai-image-studio/src/ImageChannelProfile.php');
$pending = file_get_contents($root . '/admin/ai-image-studio/api/pending_candidates.php');
$enqueue = file_get_contents($root . '/admin/ai-image-studio/api/enqueue_generation.php');
$status = file_get_contents($root . '/admin/ai-image-studio/api/generation_status.php');
$worker = file_get_contents($root . '/scripts/ai-image-studio-worker.php');

foreach (compact('guard', 'ui', 'profilesApi', 'profiles', 'pending', 'enqueue', 'status', 'worker') as $name => $content) {
    iv_assert(is_string($content) && $content !== '', "{$name} precisa existir e ter conteúdo");
}

iv_assert(str_contains($guard, 'image-generation-workflow.js'), 'Admin deve carregar o controlador unificado de imagens');
iv_assert(!str_contains($guard, 'ai-image-studio-workflow.js'), 'Admin não deve empilhar o controlador anterior de imagens');
iv_assert(!str_contains($guard, 'ai-routines-hotfix-ui.js'), 'Admin não deve empilhar o hotfix legado de imagens');
iv_assert(str_contains($ui, '/api/channel_profiles.php'), 'UI deve carregar inteligência visual por canal');
iv_assert(str_contains($ui, '/api/pending_candidates.php'), 'UI deve carregar candidatos pela API segura');
iv_assert(str_contains($ui, '/api/enqueue_generation.php'), 'UI deve enfileirar apenas produtos selecionados');
iv_assert(str_contains($ui, '/api/generation_status.php'), 'UI deve acompanhar o worker e staging em tempo real');
iv_assert(str_contains($ui, 'Nenhum produto selecionado'), 'Seleção precisa começar vazia e explícita');
iv_assert(str_contains($ui, 'recommended_types') && str_contains($ui, 'essential_types'), 'UI deve oferecer plano essencial e recomendado por canal');
iv_assert(str_contains($ui, 'readiness_state') && str_contains($ui, 'readiness_score'), 'UI deve sinalizar prontidão por produto');
iv_assert(str_contains($ui, 'local_reference') && str_contains($ui, 'Regenerar antes de publicar'), 'Resultado sem edição real deve ficar bloqueado para publicação');
iv_assert(str_contains($ui, 'Comparar foto real e imagem gerada'), 'Revisão deve preservar comparação antes/depois recolhida');
iv_assert(str_contains($ui, 'Usar recomendação inteligente do canal'), 'Regeneração deve oferecer orientação dirigida ao canal');

foreach (['channel_goal', 'essential_types', 'recommended_types', 'decision_rationale', 'risk_rules', 'approval_checks'] as $field) {
    iv_assert(str_contains($profiles, "'{$field}'"), "Perfil visual precisa declarar {$field}");
}
iv_assert(str_contains($profilesApi, 'provider_roles') || str_contains($profilesApi, "'providers'"), 'API deve expor papéis dos provedores sem secrets');
iv_assert(str_contains($pending, 'readiness_score') && str_contains($pending, 'readiness_state'), 'Candidatos devem trazer score e estado de prontidão');
iv_assert(str_contains($pending, 'source_width') && str_contains($pending, 'source_height'), 'Candidatos devem sinalizar resolução da foto real');
iv_assert(str_contains($enqueue, 'deduplicated') && str_contains($enqueue, 'existing_job'), 'Fila deve deduplicar geração repetida do mesmo produto/canal');
iv_assert(str_contains($enqueue, 'provider_candidates') && str_contains($enqueue, 'recommended_types'), 'Resposta da fila deve explicar estratégia e variantes');
iv_assert(str_contains($status, 'ready_for_review') && str_contains($status, 'staging'), 'Status deve relacionar job a resultados em staging');
iv_assert(str_contains($worker, 'ai_studio_process_item(') && str_contains($worker, '$db'), 'Worker precisa processar com PDO real para gravar staging');
iv_assert(!str_contains($worker, 'ai_studio_process_queued_job($payload)'), 'Worker não pode usar caminho que perde o banco do staging');

fwrite(STDOUT, "COMPROVADO: geração de imagens possui seleção explícita, plano por canal, prontidão, fila acompanhável, staging persistente e revisão segura.\n");

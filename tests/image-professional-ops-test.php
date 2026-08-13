<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$ops = file_get_contents($root . '/admin/assets/image-professional-ops.js');
$guard = file_get_contents($root . '/includes/admin-guard.php');

function sv_image_ops_assert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FALHOU: {$message}\n");
        exit(1);
    }
}

sv_image_ops_assert(is_string($ops) && $ops !== '', 'cockpit operacional deve existir');
sv_image_ops_assert(is_string($guard) && $guard !== '', 'admin guard deve existir');

sv_image_ops_assert(
    str_contains($guard, 'image-professional-ops.js')
    && strpos($guard, 'image-professional-ops.js') > strpos($guard, 'image-provider-health.js'),
    'cockpit deve carregar depois do controlador, safety e preflight de provider'
);

sv_image_ops_assert(
    str_contains($ops, "STORAGE_KEY = 'sv:image-studio:professional-ops:v1'")
    && str_contains($ops, 'MAX_JOBS = 300')
    && str_contains($ops, 'MAX_AGE_MS = 7 * 24 * 60 * 60 * 1000')
    && str_contains($ops, 'localStorage.setItem(STORAGE_KEY'),
    'operacao deve sobreviver a reload sem crescer indefinidamente'
);

sv_image_ops_assert(
    str_contains($ops, '/api/provider_health_check.php')
    && str_contains($ops, 'HEALTH_TTL_MS')
    && str_contains($ops, 'working_key_count')
    && str_contains($ops, "['claude','groq'].includes(provider) && !health.has_visual_editor")
    && str_contains($ops, 'preflightBeforeRun')
    && str_contains($ops, "document.addEventListener('click', preflightBeforeRun, true)"),
    'lote deve ter preflight automatico e fail-closed para credencial/editor ausente'
);

sv_image_ops_assert(
    str_contains($ops, '/api/enqueue_generation.php')
    && str_contains($ops, '/api/generation_status.php')
    && str_contains($ops, 'response.clone().json()')
    && str_contains($ops, 'recordEnqueue')
    && str_contains($ops, 'ingestStatus'),
    'cockpit deve observar o caminho real de fila/status sem substituir o controlador principal'
);

sv_image_ops_assert(
    str_contains($ops, 'QUEUE_SLA_MS = 5 * 60 * 1000')
    && str_contains($ops, 'RUN_SLA_MS = 12 * 60 * 1000')
    && str_contains($ops, 'SLA fila &gt; 5m')
    && str_contains($ops, 'SLA geração &gt; 12m'),
    'cockpit deve explicitar SLA operacional de fila e geracao'
);

sv_image_ops_assert(
    str_contains($ops, 'retryTypes(job)')
    && str_contains($ops, 'failed_types')
    && str_contains($ops, 'missing_types')
    && str_contains($ops, "newBatch('retry')")
    && str_contains($ops, 'retry_of')
    && str_contains($ops, 'Reenfileirar falhas'),
    'retry deve criar novo lote auditavel e reduzir escopo a variantes falhas/ausentes'
);

sv_image_ops_assert(
    str_contains($ops, 'refreshTrackedJobs(true)')
    && str_contains($ops, "document.addEventListener('visibilitychange'")
    && str_contains($ops, 'POLL_FALLBACK_MS = 30 * 1000')
    && str_contains($ops, 'Date.now() - lastStatusSeenAt < 25 * 1000'),
    'monitoramento deve retomar apos reload sem duplicar polling agressivo quando o controlador esta ativo'
);

sv_image_ops_assert(
    str_contains($ops, "schema:'shopvivaliz.ai-image-ops.v1'")
    && str_contains($ops, 'Exportar relatório')
    && str_contains($ops, 'provider_health')
    && str_contains($ops, 'active_batch_id'),
    'operacao deve exportar evidencia estruturada do lote e do preflight'
);

sv_image_ops_assert(
    !str_contains($ops, '/admin/ai-image-studio/api/publish')
    && !str_contains($ops, 'confirm_channel')
    && str_contains($ops, 'não publica, não aprova imagens e não altera staging'),
    'cockpit profissional nao pode publicar nem aprovar imagens automaticamente'
);

fwrite(STDOUT, "COMPROVADO: cockpit profissional adiciona preflight, rastreabilidade, SLA, retomada, retry seletivo e relatorio sem publicar.\n");

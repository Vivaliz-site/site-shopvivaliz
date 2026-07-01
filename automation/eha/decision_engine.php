<?php
/**
 * EHA Decision Engine — decide ação baseado em métricas
 */

function decide_next_step(array $metrics): string {
    // Falhas HTTP reais -> ROLLBACK imediato
    if ($metrics['checkout_fail'] ?? false) return 'ROLLBACK';
    if (!($metrics['db_ok']       ?? true)) return 'ROLLBACK';
    if ($metrics['error_high']    ?? false) return 'ROLLBACK';

    // Falha E2E com site HTTP saudável -> DEPLOY_OK (flakiness de CI)
    // Falha E2E com HTTP degradado -> CREATE_PR para investigar
    if ($metrics['e2e_failed'] ?? false) {
        $site_healthy = ($metrics['checkout_ok'] ?? false) && ($metrics['api_ok'] ?? true) && ($metrics['pages_ok'] ?? true);
        if (!$site_healthy) return 'CREATE_PR';
    }

    if ($metrics['error_medium']  ?? false) return 'CREATE_PR';
    if ($metrics['error_low']     ?? false) return 'AUTO_FIX';
    return 'DEPLOY_OK';
}

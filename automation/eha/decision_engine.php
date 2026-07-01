<?php
/**
 * EHA Decision Engine — decide ação baseado em métricas
 */

function decide_next_step(array $metrics): string {
    if ($metrics['checkout_fail'] ?? false) return 'ROLLBACK';
    if ($metrics['e2e_failed']    ?? false) return 'ROLLBACK';
    if (!($metrics['db_ok']       ?? true)) return 'ROLLBACK';
    if ($metrics['error_high']    ?? false) return 'ROLLBACK';
    if ($metrics['error_medium']  ?? false) return 'CREATE_PR';
    if ($metrics['error_low']     ?? false) return 'AUTO_FIX';
    return 'DEPLOY_OK';
}

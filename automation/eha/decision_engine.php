<?php
/**
 * EHA Decision Engine — decide ação baseado em métricas
 */

define('E2E_FLAKINESS_THRESHOLD', 10);

function decide_next_step(array $metrics): string {
    // Falhas HTTP reais -> ROLLBACK imediato
    if ($metrics['checkout_fail'] ?? false) return 'ROLLBACK';
    if (!($metrics['db_ok']       ?? true)) return 'ROLLBACK';
    if ($metrics['error_high']    ?? false) return 'ROLLBACK';

    // Falha E2E com site HTTP saudável: verifica se é flakiness ou falha persistente
    if ($metrics['e2e_failed'] ?? false) {
        $site_healthy = ($metrics['checkout_ok'] ?? false)
                     && ($metrics['api_ok']      ?? true)
                     && ($metrics['pages_ok']    ?? true);
        if (!$site_healthy) return 'CREATE_PR';

        // Falha persistente (>= threshold) -> CREATE_PR para investigação
        // Falha esporádica -> DEPLOY_OK (flakiness de CI)
        $consecutive = count_consecutive_e2e_failures();
        if ($consecutive >= E2E_FLAKINESS_THRESHOLD) return 'CREATE_PR';
    }

    if ($metrics['error_medium']  ?? false) return 'CREATE_PR';
    if ($metrics['error_low']     ?? false) return 'AUTO_FIX';
    return 'DEPLOY_OK';
}

/**
 * Conta quantos runs consecutivos (mais recentes) tiveram e2e_failed=true.
 * Lê run_history.jsonl de trás para frente e para no primeiro run sem falha.
 */
function count_consecutive_e2e_failures(): int
{
    $history_path = __DIR__ . '/reports/run_history.jsonl';
    if (!file_exists($history_path)) return 0;

    $lines = array_reverse(
        array_filter(
            file($history_path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES)
        )
    );

    $count = 0;
    foreach ($lines as $line) {
        $r = json_decode($line, true);
        if (!is_array($r)) continue;
        if ($r['e2e_failed'] ?? false) {
            $count++;
        } else {
            break;
        }
    }
    return $count;
}

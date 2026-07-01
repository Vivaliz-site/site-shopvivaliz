<?php
/**
 * EHA Run — orquestrador principal do CI Autônomo Contínuo
 * Chamado pelo workflow a cada 15 minutos: php automation/eha/run.php
 */

require_once __DIR__ . '/health_check.php';
require_once __DIR__ . '/classifier.php';
require_once __DIR__ . '/decision_engine.php';
require_once __DIR__ . '/auto_fixer.php';
require_once __DIR__ . '/pr_creator.php';
require_once __DIR__ . '/validator.php';
require_once dirname(__DIR__) . '/auto_fix.php';
require_once dirname(__DIR__) . '/create_auto_pr.php';

$start = microtime(true);

// 1. Coleta métricas reais (uma única vez)
$metrics = collect_metrics();
_eha_log('METRICS ' . json_encode($metrics));

// 2. Valida release reaproveitando as métricas já coletadas
$validation = validate_final_release($metrics);
_eha_log('VALIDATION status=' . $validation['status']);

// 3. Decide próximo passo
$action = decide_next_step($metrics);
_eha_log("DECISION action=$action");

// 4. Executa loop
$loop = run_loop($metrics);

// 5. Salva relatório final
$elapsed = round(microtime(true) - $start, 2);
$report  = [
    'action'     => $action,
    'metrics'    => $metrics,
    'validation' => $validation,
    'loop'       => $loop,
    'elapsed_s'  => $elapsed,
    'run_id'     => getenv('GITHUB_RUN_NUMBER') ?: 'local-' . time(),
];

$dir = __DIR__ . '/reports';
if (!is_dir($dir)) mkdir($dir, 0755, true);
file_put_contents($dir . '/last_run.json', json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

echo "EHA: $action in {$elapsed}s | status={$validation['status']}\n";

// Sempre sai com 0 — o gate de pipeline é o step 'Fail if BLOCKED' no workflow YAML.
// Se run.php sair com 1, o step 'Fail if BLOCKED' nunca é executado e o contexto
// de erro se perde. O workflow lê last_status.txt e decide se bloqueia.
exit(0);

// ─────────────────────────────────────────────────────

function run_loop(array|string $error_log): array
{
    $risk = classify($error_log);
    $issue = eha_detect_issue($error_log);
    $response = [
        'risk' => $risk,
        'issue' => $issue,
        'handled' => false,
        'result' => null,
    ];

    _eha_log("LOOP risk=$risk issue=$issue");

    if ($issue === 'none') {
        $response['result'] = [
            'status' => 'no_action',
            'note' => 'Metricas saudaveis; nenhuma correcao automatica necessaria.',
        ];
        _eha_log('LOOP result=' . json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        return $response;
    }

    if ($risk === 'LOW') {
        $response['result'] = \auto_fix($issue) ?? eha_auto_fix($issue);
        $response['handled'] = $response['result'] !== null;
    }

    if ($risk === 'MEDIUM') {
        $response['result'] = eha_create_auto_pr("EHA: {$issue}");
        $response['handled'] = true;
    }

    if ($risk === 'HIGH') {
        $response['result'] = eha_rollback($issue);
        $response['handled'] = true;
    }

    _eha_log('LOOP result=' . json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    return $response;
}

function eha_detect_issue(array|string $error_log): string
{
    if (is_string($error_log)) {
        $normalized = strtolower($error_log);
        if (str_contains($normalized, 'missing image')) {
            return 'missing_image';
        }
        if (str_contains($normalized, 'missing route') || str_contains($normalized, '404')) {
            return 'missing_route';
        }
        if (str_contains($normalized, 'null') || str_contains($normalized, 'undefined')) {
            return 'null_error';
        }
        return trim($error_log) !== '' ? trim($error_log) : 'null_error';
    }

    if (!empty($error_log['checkout_fail']) || !empty($error_log['e2e_failed']) || !empty($error_log['error_high'])) {
        return 'checkout_fail';
    }

    $errorCount = (int)($error_log['error_count'] ?? 0);
    if ($errorCount === 0 && empty($error_log['error_low']) && empty($error_log['error_medium'])) {
        return 'none';
    }

    if (!empty($error_log['error_medium'])) {
        return 'missing_route';
    }

    $recentErrors = $error_log['recent_errors'] ?? [];
    if (is_array($recentErrors) && $recentErrors !== []) {
        return eha_detect_issue((string) $recentErrors[0]);
    }

    return 'null_error';
}

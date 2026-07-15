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
$e2e_consecutive = count_consecutive_e2e_failures();
_eha_log("DECISION action=$action e2e_consecutive=$e2e_consecutive");

// 4. Executa loop
$loop = run_loop($metrics);

// 5. Salva relatório final
$elapsed = round(microtime(true) - $start, 2);
$report  = [
    'action'          => $action,
    'metrics'         => $metrics,
    'validation'      => $validation,
    'loop'            => $loop,
    'e2e_consecutive' => $e2e_consecutive,
    'elapsed_s'       => $elapsed,
    'run_id'          => getenv('GITHUB_RUN_NUMBER') ?: 'local-' . time(),
];

$dir = __DIR__ . '/reports';
if (!is_dir($dir)) mkdir($dir, 0755, true);

// Inclui detalhes dos testes E2E do Playwright se disponível
$e2e_details = read_e2e_details($dir);
if ($e2e_details !== []) {
    $report['e2e_details'] = $e2e_details;
}

file_put_contents($dir . '/last_run.json', json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

// 6. Salva histórico compacto (janela deslizante de 200 execuções)
$history_record = json_encode([
    'run_id'      => $report['run_id'],
    'ts'          => $metrics['timestamp'] ?? date('c'),
    'status'      => $validation['status'],
    'action'      => $action,
    'elapsed_s'   => $elapsed,
    'checkout_ok' => (bool)($metrics['checkout_ok'] ?? false),
    'api_ok'      => (bool)($metrics['api_ok'] ?? false),
    'db_ok'       => (bool)($metrics['db_ok'] ?? false),
    'e2e_failed'  => (bool)($metrics['e2e_failed'] ?? false),
    'e2e_consecutive' => $e2e_consecutive,
    'error_count' => (int)($metrics['error_count'] ?? 0),
], JSON_UNESCAPED_UNICODE) . "\n";
file_put_contents($dir . '/run_history.jsonl', $history_record, FILE_APPEND | LOCK_EX);
$lines = @file($dir . '/run_history.jsonl') ?: [];
if (count($lines) > 200) {
    file_put_contents($dir . '/run_history.jsonl', implode('', array_slice($lines, -200)));
}

echo "EHA: $action in {$elapsed}s | status={$validation['status']} | e2e_consecutive=$e2e_consecutive\n";

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

function read_e2e_details(string $reports_dir): array
{
    $path = $reports_dir . '/playwright-results.json';
    if (!file_exists($path)) return [];
    $data = @json_decode(@file_get_contents($path) ?: '{}', true) ?: [];
    if (empty($data)) return [];

    $stats = $data['stats'] ?? [];
    $failed_tests = [];

    $collect = function(array $suites, string $parent = '') use (&$collect, &$failed_tests): void {
        foreach ($suites as $suite) {
            $ctx = trim($parent . ' > ' . ($suite['title'] ?? ''), ' >');
            foreach ($suite['specs'] ?? [] as $spec) {
                if (!($spec['ok'] ?? true)) {
                    $err = '';
                    foreach ($spec['tests'] ?? [] as $test) {
                        foreach ($test['results'] ?? [] as $result) {
                            if (!empty($result['error']['message'])) {
                                $err = substr(trim($result['error']['message']), 0, 300);
                                break 2;
                            }
                        }
                    }
                    $failed_tests[] = [
                        'suite' => $ctx,
                        'test'  => $spec['title'] ?? '?',
                        'error' => $err,
                    ];
                }
            }
            $collect($suite['suites'] ?? [], $ctx);
        }
    };

    $collect($data['suites'] ?? []);

    return [
        'total'        => ($stats['expected'] ?? 0) + ($stats['unexpected'] ?? 0),
        'passed'       => $stats['expected'] ?? 0,
        'failed'       => $stats['unexpected'] ?? 0,
        'flaky'        => $stats['flaky'] ?? 0,
        'failed_tests' => $failed_tests,
    ];
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

    // checkout_fail real (HTTP falhou) -> issue real
    // e2e_failed com checkout_ok -> flakiness CI, não é issue real
    $real_checkout_fail = !empty($error_log['checkout_fail']);
    $e2e_only_fail      = !empty($error_log['e2e_failed']) && !empty($error_log['checkout_ok']);
    if ($real_checkout_fail || (!$e2e_only_fail && !empty($error_log['e2e_failed'])) || !empty($error_log['error_high'])) {
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

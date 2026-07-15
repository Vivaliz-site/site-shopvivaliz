<?php
/**
 * Orchestrator Scheduler — ShopVivaliz
 *
 * Chamado pelo cPanel Cron a cada 5 minutos:
 *   * /5 * * * * php /home/USER/public_html/dev/api/orchestrator/scheduler.php
 *
 * Também pode ser chamado via HTTP com CRON_SECRET:
 *   GET /api/orchestrator/scheduler.php?secret=CRON_SECRET
 *
 * Responsabilidades:
 *   1. Verificar se cada tarefa periódica precisa ser enfileirada
 *   2. Processar até 3 tasks da fila por execução
 *   3. Logar em logs/orchestrator.log
 */

declare(strict_types=1);

// ── Bootstrap ─────────────────────────────────────────────────────────────────
(static function () {
    $f = dirname(__DIR__, 2) . '/.env';
    if (!is_file($f)) return;
    foreach (file($f, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) continue;
        [$k, $v] = explode('=', $line, 2);
        $k = trim($k); $v = trim(trim($v), '"\'');
        if ($k !== '' && getenv($k) === false) putenv("$k=$v");
    }
})();

require_once __DIR__ . '/queue.php';

// ── Helpers ───────────────────────────────────────────────────────────────────
function osch_env(string $key): string { return (string)(getenv($key) ?: ''); }

function osch_log(string $msg): void {
    $dir = dirname(__DIR__, 2) . '/logs';
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    $line = '[' . date('c') . '] [scheduler] ' . $msg . "\n";
    file_put_contents($dir . '/orchestrator.log', $line, FILE_APPEND | LOCK_EX);
}

function osch_http_get(string $url, int $timeout = 20): array {
    $ctx = stream_context_create(['http' => [
        'method'  => 'GET',
        'timeout' => $timeout,
        'ignore_errors' => true,
        'header'  => 'User-Agent: ShopVivaliz-Orchestrator/1.0',
    ]]);
    $body = @file_get_contents($url, false, $ctx);
    if ($body === false) return ['ok' => false, 'error' => 'unreachable'];
    $data = json_decode($body, true);
    return is_array($data) ? $data : ['ok' => false, 'raw' => substr($body, 0, 500)];
}

// ── Definição de Tarefas Periódicas ───────────────────────────────────────────
// interval_s: intervalo mínimo entre execuções em segundos
function osch_periodic_tasks(): array {
    return [
        'watchdog'         => ['interval_s' => 1800,  'priority' => 'high',   'endpoint' => '/api/agent/autonomous-watchdog.php'],
        'report'           => ['interval_s' => 86400, 'priority' => 'normal', 'endpoint' => '/api/agent/autonomous-report.php'],
        'price-sync-check' => ['interval_s' => 3600,  'priority' => 'normal', 'endpoint' => '/api/agent/cron-dispatcher.php?task=status'],
    ];
}

/**
 * Verifica quando uma tarefa rodou pela última vez (via fila).
 * Retorna timestamp Unix ou 0 se nunca rodou.
 */
function osch_last_run(string $type): int {
    $tasks = queue_list(); // todas
    $last  = 0;
    foreach ($tasks as $t) {
        if ($t['type'] !== $type) continue;
        if (!in_array($t['status'] ?? '', ['done', 'failed'], true)) continue;
        $ts = strtotime($t['done_at'] ?? '') ?: 0;
        if ($ts > $last) $last = $ts;
    }
    return $last;
}

/**
 * Enfileira tasks periódicas que estão atrasadas.
 */
function osch_schedule_due_tasks(): array {
    $now      = time();
    $enqueued = [];

    foreach (osch_periodic_tasks() as $type => $cfg) {
        $last    = osch_last_run($type);
        $elapsed = $now - $last;

        if ($elapsed >= $cfg['interval_s']) {
            $id = queue_push($type, $cfg['priority'], ['endpoint' => $cfg['endpoint']]);
            if ($id !== false) {
                $enqueued[] = ['type' => $type, 'id' => $id, 'elapsed_s' => $elapsed];
                osch_log("enfileirado: type=$type id=$id elapsed={$elapsed}s");
            }
        }
    }

    return $enqueued;
}

/**
 * Processa até $limit tasks da fila, chamando o endpoint de cada uma.
 */
function osch_process_queue(string $baseUrl, int $limit = 3): array {
    $processed = [];

    for ($i = 0; $i < $limit; $i++) {
        $task = queue_pop();
        if ($task === null) break;

        $id       = $task['id'];
        $type     = $task['type'];
        $endpoint = $task['data']['endpoint'] ?? null;

        osch_log("processando: type=$type id=$id");

        if ($endpoint === null) {
            queue_finish($id, false, ['error' => 'endpoint não definido']);
            osch_log("falhou (sem endpoint): type=$type id=$id");
            $processed[] = ['type' => $type, 'id' => $id, 'ok' => false, 'error' => 'sem endpoint'];
            continue;
        }

        $url    = $baseUrl . $endpoint;
        $secret = osch_env('CRON_SECRET');
        if ($secret !== '' && !str_contains($url, 'secret=')) {
            $sep  = str_contains($url, '?') ? '&' : '?';
            $url .= $sep . 'secret=' . urlencode($secret);
        }

        $result  = osch_http_get($url);
        $success = isset($result['ok']) ? (bool)$result['ok'] : !isset($result['error']);

        // Para watchdog e report, considerar ok se retornou dados válidos
        if (isset($result['agent']) || isset($result['all_ok']) || isset($result['catalog'])) {
            $success = true;
        }

        $retries = (int)($task['retries'] ?? 0);
        queue_finish($id, $success, $result, $retries);

        $status = $success ? 'ok' : 'failed';
        osch_log("concluído: type=$type id=$id status=$status");

        $processed[] = ['type' => $type, 'id' => $id, 'ok' => $success, 'result_summary' => array_intersect_key($result, array_flip(['agent', 'all_ok', 'catalog', 'status', 'error']))];
    }

    return $processed;
}

// ── Autenticação HTTP ─────────────────────────────────────────────────────────
$isCli      = PHP_SAPI === 'cli';
$cronSecret = osch_env('CRON_SECRET');

if (!$isCli) {
    if ($cronSecret !== '' && ($_GET['secret'] ?? '') !== $cronSecret) {
        http_response_code(403);
        header('Content-Type: application/json');
        echo json_encode(['ok' => false, 'error' => 'Acesso negado.']);
        exit;
    }
}

// ── Execução Principal ────────────────────────────────────────────────────────
$baseUrl   = rtrim(osch_env('SITE_URL') ?: 'https://dev.shopvivaliz.com.br', '/');
$maxProc   = (int)($_GET['max'] ?? $argv[1] ?? 3);
$maxProc   = max(1, min(10, $maxProc));

osch_log("início (base=$baseUrl max=$maxProc)");

$enqueued  = osch_schedule_due_tasks();
$processed = osch_process_queue($baseUrl, $maxProc);
$qStatus   = queue_status();

osch_log(sprintf(
    'fim: enfileiradas=%d processadas=%d fila_pending=%d',
    count($enqueued),
    count($processed),
    $qStatus['counts']['pending'] ?? 0
));

$output = [
    'ok'         => true,
    'run_at'     => date('c'),
    'enqueued'   => $enqueued,
    'processed'  => $processed,
    'queue'      => $qStatus,
];

if (!$isCli) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($output, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
} else {
    echo json_encode($output, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
}

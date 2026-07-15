<?php
/**
 * Orchestrator Status — ShopVivaliz
 *
 * Endpoint HTTP protegido por CRON_SECRET para visualizar o estado do orquestrador.
 *
 * GET /api/orchestrator/status.php?secret=CRON_SECRET
 * GET /api/orchestrator/status.php?secret=CRON_SECRET&detail=1   (inclui tasks completas)
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

header_remove('X-Powered-By');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

// ── Autenticação ──────────────────────────────────────────────────────────────
$cronSecret = (string)(getenv('CRON_SECRET') ?: '');
if ($cronSecret !== '' && ($_GET['secret'] ?? '') !== $cronSecret) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Acesso negado.']);
    exit;
}

// ── Definição dos intervalos esperados ────────────────────────────────────────
$periodicDefs = [
    'watchdog'         => 1800,
    'report'           => 86400,
    'price-sync-check' => 3600,
];

// ── Coleta estado da fila ─────────────────────────────────────────────────────
$status  = queue_status();
$allTasks = queue_list();

// ── Calcula última e próxima execução por tipo ────────────────────────────────
$typeInfo = [];
$now      = time();

foreach ($periodicDefs as $type => $intervalS) {
    $lastDone = null;
    $lastTs   = 0;

    foreach ($allTasks as $t) {
        if ($t['type'] !== $type) continue;
        if (!in_array($t['status'] ?? '', ['done', 'failed'], true)) continue;
        $ts = strtotime($t['done_at'] ?? '') ?: 0;
        if ($ts > $lastTs) {
            $lastTs   = $ts;
            $lastDone = $t['done_at'];
        }
    }

    $nextTs  = $lastTs > 0 ? $lastTs + $intervalS : null;
    $overdue = $nextTs !== null && $nextTs < $now;
    $pendingCount = count(array_filter($allTasks, fn($t) => $t['type'] === $type && ($t['status'] ?? '') === 'pending'));

    $typeInfo[$type] = [
        'interval_s'      => $intervalS,
        'last_run_at'     => $lastDone,
        'next_run_at'     => $nextTs ? date('c', $nextTs) : null,
        'overdue'         => $overdue,
        'pending_count'   => $pendingCount,
        'elapsed_s'       => $lastTs > 0 ? ($now - $lastTs) : null,
    ];
}

// ── Log recente (últimas 20 linhas) ──────────────────────────────────────────
$logFile   = dirname(__DIR__, 2) . '/logs/orchestrator.log';
$recentLog = [];
if (is_file($logFile)) {
    $lines     = file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
    $recentLog = array_slice($lines, -20);
}

// ── Resposta ──────────────────────────────────────────────────────────────────
$detail = (bool)($_GET['detail'] ?? false);

$response = [
    'ok'          => true,
    'generated_at'=> date('c'),
    'queue'       => $status,
    'tasks_by_type' => $typeInfo,
    'log_tail'    => $recentLog,
];

if ($detail) {
    $response['all_tasks'] = $allTasks;
}

echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

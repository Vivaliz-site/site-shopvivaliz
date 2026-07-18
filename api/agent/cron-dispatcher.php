<?php
/**
 * Cron Dispatcher — ShopVivaliz
 *
 * Executado pelo cPanel Cron Jobs. Despacha tarefas autônomas periódicas.
 * Configurar no cPanel:
 *   Cada 30 minutos: php /home/USER/public_html/dev/api/agent/cron-dispatcher.php watchdog
 *   Diariamente 03:00: php /home/USER/public_html/dev/api/agent/cron-dispatcher.php report
 *
 * Uso via HTTP (requer CRON_SECRET):
 *   GET /api/agent/cron-dispatcher.php?task=watchdog&secret=CRON_SECRET
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

function cd_env(string $key): string { return (string)(getenv($key) ?: ''); }

function cd_log(string $task, string $msg): void {
    $dir = dirname(__DIR__, 2) . '/logs';
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    $line = '[' . date('c') . '] [' . $task . '] ' . $msg . "\n";
    file_put_contents($dir . '/cron-dispatcher.log', $line, FILE_APPEND | LOCK_EX);
}

function cd_http_get(string $url): array {
    $ctx = stream_context_create(['http' => [
        'method'  => 'GET',
        'timeout' => 15,
        'header'  => 'User-Agent: ShopVivaliz-Cron/1.0',
    ]]);
    $body = @file_get_contents($url, false, $ctx);
    if ($body === false) return ['ok' => false, 'error' => 'unreachable'];
    $data = json_decode($body, true);
    return is_array($data) ? $data : ['ok' => false, 'raw' => substr($body, 0, 200)];
}

function cd_php_json(string $relativeScript): array {
    $script = dirname(__DIR__, 2) . '/' . ltrim($relativeScript, '/');
    if (!is_file($script)) {
        return ['ok' => false, 'error' => 'missing_script'];
    }
    $php = PHP_BINARY ?: 'php';
    $cmd = escapeshellarg($php) . ' ' . escapeshellarg($script) . ' 2>&1';
    $raw = @shell_exec($cmd);
    if (!is_string($raw) || trim($raw) === '') {
        return ['ok' => false, 'error' => 'empty_output'];
    }
    $data = json_decode($raw, true);
    return is_array($data) ? $data : ['ok' => false, 'raw' => substr($raw, 0, 500)];
}

// ── Autenticação ──────────────────────────────────────────────────────────────
$cronSecret = cd_env('CRON_SECRET');
$isCli      = PHP_SAPI === 'cli';

if (!$isCli) {
    // Via HTTP — exige CRON_SECRET
    if ($cronSecret !== '' && ($_GET['secret'] ?? '') !== $cronSecret) {
        http_response_code(403);
        header('Content-Type: application/json');
        echo json_encode(['ok' => false, 'error' => 'Acesso negado.']);
        exit;
    }
    $task = trim($_GET['task'] ?? 'status');
} else {
    $task = trim($argv[1] ?? 'status');
}

// ── Base URL ──────────────────────────────────────────────────────────────────
$baseUrl = rtrim(cd_env('SITE_URL') ?: 'https://dev.shopvivaliz.com.br', '/');

// ── Tasks ─────────────────────────────────────────────────────────────────────

function task_watchdog(string $baseUrl): array {
    $result = cd_php_json('/api/agent/autonomous-watchdog.php');
    $status = $result['status'] ?? 'unknown';
    cd_log('watchdog', 'status=' . $status . ' alerts=' . count($result['alerts'] ?? []));
    if (!empty($result['alerts'])) {
        foreach ($result['alerts'] as $alert) {
            cd_log('watchdog', 'ALERTA: ' . (is_string($alert) ? $alert : json_encode($alert)));
        }
    }
    return $result;
}

function task_report(string $baseUrl): array {
    $result = cd_php_json('/api/agent/autonomous-report.php');
    $catalog = $result['catalog'] ?? [];
    cd_log('report', sprintf(
        'total=%d zero_price=%d no_image=%d ml_connected=%s',
        $catalog['total'] ?? 0,
        $catalog['zero_price'] ?? 0,
        $catalog['no_image'] ?? 0,
        ($result['integrations']['ml_oauth_connected'] ?? false) ? 'true' : 'false'
    ));
    return $result;
}

function task_status(string $baseUrl): array {
    $report   = cd_php_json('/api/agent/autonomous-report.php');
    $watchdog = cd_php_json('/api/agent/autonomous-watchdog.php');
    return ['report' => $report, 'watchdog' => $watchdog];
}

// ── Dispatch ──────────────────────────────────────────────────────────────────
cd_log($task, 'início');

$result = match($task) {
    'watchdog' => task_watchdog($baseUrl),
    'report'   => task_report($baseUrl),
    'status'   => task_status($baseUrl),
    default    => ['ok' => false, 'error' => "Task desconhecida: $task"],
};

cd_log($task, 'fim');

if (!$isCli) {
    header('Content-Type: application/json');
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
} else {
    echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
}

<?php
/**
 * Status API — retorna JSON rico do sistema ShopVivaliz.
 * GET /claude/api/status.php
 * GET /claude/api/status.php?format=summary  (resposta mínima para monitoring)
 */
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('Access-Control-Allow-Origin: *');

$t0 = microtime(true);

$format = $_GET['format'] ?? 'full';

// --- health checks paralelos ---
$checks = [
    'homepage' => 'http://localhost/',
    'api'      => 'http://localhost/claude/api/health.php',
    'catalogo' => 'http://localhost/claude/catalogo/',
    'carrinho' => 'http://localhost/claude/carrinho/',
];

$mh      = curl_multi_init();
$handles = [];

foreach ($checks as $key => $url) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 8,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_USERAGENT      => 'EHA-Status/1.0',
    ]);
    $handles[$key] = $ch;
    curl_multi_add_handle($mh, $ch);
}

$active = null;
do { curl_multi_exec($mh, $active); } while ($active);

$http = [];
foreach ($handles as $key => $ch) {
    $http[$key] = [
        'code' => curl_getinfo($ch, CURLINFO_HTTP_CODE),
        'ms'   => (int)(curl_getinfo($ch, CURLINFO_TOTAL_TIME) * 1000),
    ];
    curl_multi_remove_handle($mh, $ch);
    curl_close($ch);
}
curl_multi_close($mh);

$homepage_ok = ($http['homepage']['code'] ?? 0) === 200;
$api_ok      = in_array($http['api']['code'] ?? 0, [200, 204], true);
$catalogo_ok = ($http['catalogo']['code'] ?? 0) < 500;
$carrinho_ok = ($http['carrinho']['code'] ?? 0) < 500;
$all_ok      = $homepage_ok && $api_ok && $catalogo_ok && $carrinho_ok;

// --- EHA last run ---
$last_run_path = dirname(__DIR__, 2) . '/automation/eha/reports/last_run.json';
$last_run      = @json_decode(@file_get_contents($last_run_path) ?: '{}', true) ?: [];

// --- EHA run history ---
$history_path = dirname(__DIR__, 2) . '/automation/eha/reports/run_history.jsonl';
$eha_runs     = [];
if (is_readable($history_path)) {
    $lines = file($history_path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach (array_slice($lines, -50) as $line) {
        $r = json_decode($line, true);
        if ($r) $eha_runs[] = $r;
    }
}

$total_runs  = count($eha_runs);
$ok_runs     = count(array_filter($eha_runs, fn($r) => ($r['status'] ?? '') === 'READY_FOR_PRODUCTION'));
$uptime_pct  = $total_runs > 0 ? round($ok_runs / $total_runs * 100, 1) : null;
$streak      = 0;
foreach (array_reverse($eha_runs) as $r) {
    if (($r['status'] ?? '') === 'READY_FOR_PRODUCTION') $streak++;
    else break;
}
$avg_elapsed = $total_runs > 0
    ? round(array_sum(array_column($eha_runs, 'elapsed_s')) / $total_runs, 2)
    : null;

$elapsed = round(microtime(true) - $t0, 3);

if ($format === 'summary') {
    echo json_encode([
        'ok'        => $all_ok,
        'status'    => $all_ok ? 'READY_FOR_PRODUCTION' : 'DEGRADED',
        'uptime'    => $uptime_pct,
        'streak'    => $streak,
        'eha_run'   => $last_run['run_id'] ?? null,
        'eha_action'=> $last_run['action'] ?? null,
        'ts'        => date('c'),
        'elapsed_s' => $elapsed,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    exit;
}

echo json_encode([
    'ok'      => $all_ok,
    'status'  => $all_ok ? 'READY_FOR_PRODUCTION' : 'DEGRADED',
    'ts'      => date('c'),
    'elapsed_s' => $elapsed,
    'checks'  => [
        'homepage' => array_merge($http['homepage'], ['ok' => $homepage_ok]),
        'api'      => array_merge($http['api'],      ['ok' => $api_ok]),
        'catalogo' => array_merge($http['catalogo'], ['ok' => $catalogo_ok]),
        'carrinho' => array_merge($http['carrinho'], ['ok' => $carrinho_ok]),
    ],
    'eha' => [
        'run_id'    => $last_run['run_id']    ?? null,
        'action'    => $last_run['action']    ?? null,
        'risk'      => $last_run['loop']['risk'] ?? null,
        'e2e_failed'=> $last_run['metrics']['e2e_failed'] ?? null,
        'elapsed_s' => $last_run['elapsed_s'] ?? null,
        'ts'        => $last_run['metrics']['timestamp'] ?? null,
    ],
    'stats' => [
        'total_runs'  => $total_runs,
        'ok_runs'     => $ok_runs,
        'uptime_pct'  => $uptime_pct,
        'streak'      => $streak,
        'avg_elapsed' => $avg_elapsed,
    ],
    'recent_runs' => array_reverse(array_slice($eha_runs, -5)),
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

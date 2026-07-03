<?php
declare(strict_types=1);

header_remove('X-Powered-By');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function svaw_root(): string { return dirname(__DIR__, 2); }

function svaw_probe(string $url, int $timeout = 8): array {
    $ctx = stream_context_create(['http' => ['method' => 'GET', 'timeout' => $timeout, 'ignore_errors' => true]]);
    $start = microtime(true);
    $body  = @file_get_contents($url, false, $ctx);
    $ms    = (int)((microtime(true) - $start) * 1000);
    $status = 0;
    if (isset($http_response_header[0])) {
        preg_match('/HTTP\/[\d.]+ (\d+)/', $http_response_header[0], $m);
        $status = (int)($m[1] ?? 0);
    }
    $ok = $status >= 200 && $status < 500;
    return ['url' => $url, 'status' => $status, 'ok' => $ok, 'ms' => $ms];
}

$base = 'https://dev.shopvivaliz.com.br';

$probes = [
    'home'          => svaw_probe($base . '/'),
    'health'        => svaw_probe($base . '/api/health.php'),
    'catalog_api'   => svaw_probe($base . '/api/catalog/products.php'),
    'squad_chat'    => svaw_probe($base . '/api/agent/squad-chat.php?health=1'),
    'ml_token'      => svaw_probe($base . '/api/ml/token/status'),
    'self_test'     => svaw_probe($base . '/api/agent/autonomous-report.php'),
];

$allOk  = !in_array(false, array_column($probes, 'ok'), true);
$alerts = [];
foreach ($probes as $name => $probe) {
    if (!$probe['ok']) {
        $alerts[] = "$name: HTTP {$probe['status']} ({$probe['ms']}ms)";
    }
}

// Log local se houver falhas
if (!empty($alerts)) {
    $logDir = svaw_root() . '/logs';
    if (!is_dir($logDir)) @mkdir($logDir, 0755, true);
    $entry = json_encode([
        'ts'     => date('c'),
        'alerts' => $alerts,
        'probes' => $probes,
    ]) . "\n";
    file_put_contents($logDir . '/watchdog.log', $entry, FILE_APPEND | LOCK_EX);
}

echo json_encode([
    'agent'        => 'autonomous-watchdog',
    'generated_at' => date('c'),
    'all_ok'       => $allOk,
    'alerts'       => $alerts,
    'probes'       => $probes,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);

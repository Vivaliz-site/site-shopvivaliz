<?php
declare(strict_types=1);

header_remove('X-Powered-By');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$root = dirname(__DIR__, 2);

function sv_monitor_first_existing(array $paths): ?string
{
    foreach ($paths as $path) {
        if (is_file($path)) {
            return $path;
        }
    }
    return null;
}

$backlogPath = sv_monitor_first_existing([
    $root . '/docs/backlog.json',
    $root . '/backlog.json',
    $root . '/autodev/backlog.json',
]);
$roadmapPath = sv_monitor_first_existing([
    $root . '/docs/roadmap.json',
    $root . '/roadmap.json',
    $root . '/autodev/roadmap.json',
]);
$logsDir = $root . '/logs';
if (!is_dir($logsDir)) {
    @mkdir($logsDir, 0755, true);
}

$checks = [
    'Painel admin presente' => is_file($root . '/admin/index.php'),
    'Monitor web presente' => is_file($root . '/admin/monitor/index.php'),
    'Cliente autodev presente' => is_file($root . '/autodev/client.js'),
    'Diretorio logs gravavel' => is_dir($logsDir) && is_writable($logsDir),
    'Watchdog configurado' => is_file($root . '/api/agent/autonomous-watchdog.php') || is_file($root . '/autodev/watchdog.php'),
    'Backlog localizado' => $backlogPath !== null,
    'Roadmap localizado' => $roadmapPath !== null,
];

$logFiles = [
    'watchdog' => $logsDir . '/watchdog.log',
    'dev_agent' => $logsDir . '/dev-agent.log',
    'autodev_agent' => $root . '/autodev/logs/agent.log',
];
$logStatus = [];
foreach ($logFiles as $name => $path) {
    $logStatus[$name] = [
        'present' => is_file($path),
        'size_bytes' => is_file($path) ? (int)filesize($path) : 0,
        'modified_at' => is_file($path) ? date('c', (int)filemtime($path)) : null,
    ];
}

$ok = !in_array(false, $checks, true);
http_response_code($ok ? 200 : 207);

echo json_encode([
    'ok' => $ok,
    'status' => $ok ? 'ok' : 'attention',
    'generated_at' => date('c'),
    'root' => $root,
    'checks' => $checks,
    'files' => [
        'backlog' => $backlogPath,
        'roadmap' => $roadmapPath,
    ],
    'logs' => $logStatus,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);

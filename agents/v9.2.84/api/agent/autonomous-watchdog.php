<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

$root = dirname(__DIR__, 2);
$autoloads = [$root . '/app/Support.php', $root . '/vendor/autoload.php'];
foreach ($autoloads as $file) {
    if (is_file($file)) require_once $file;
}

require_once $root . '/app/AutonomousWatchdogAgent.php';

try {
    $params = $_REQUEST;
    $params['run_loop'] = isset($params['run_loop']) ? (bool)$params['run_loop'] : true;
    $agent = new ShopvivalizAutonomousWatchdogAgent();
    echo json_encode($agent->run($params), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'agent' => 'autonomous_watchdog', 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
}
file_put_contents(
    '/home/ubuntu/site-shopvivaliz/scripts/heartbeat.txt',
    "heartbeat: " . date('c') . PHP_EOL,
    FILE_APPEND
);

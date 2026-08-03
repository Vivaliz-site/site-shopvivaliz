<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$root = dirname(__DIR__, 2);
require_once $root . '/app/AutonomousWatchdogAgent.php';

try {
    $result = (new ShopvivalizAutonomousWatchdogAgent())->run($_REQUEST);
    http_response_code(($result['ok'] ?? false) === true ? 200 : 500);
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'agent' => 'autonomous_watchdog', 'execution_status' => 'failed', 'error' => 'execution_failed'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
}

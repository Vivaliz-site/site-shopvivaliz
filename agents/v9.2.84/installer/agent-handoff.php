<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

$root = dirname(__DIR__);
foreach ([$root . '/app/Support.php', $root . '/vendor/autoload.php'] as $file) {
    if (is_file($file)) require_once $file;
}

require_once $root . '/app/AutonomousWatchdogAgent.php';

$params = $_REQUEST;
$params['run_loop'] = isset($params['run_loop']) ? (bool)$params['run_loop'] : true;
$params['cycles'] = isset($params['cycles']) ? (int)$params['cycles'] : 3;
$params['chunk_size'] = isset($params['chunk_size']) ? (int)$params['chunk_size'] : 10;
$params['image_limit'] = isset($params['image_limit']) ? (int)$params['image_limit'] : 250;

try {
    $result = (new ShopvivalizAutonomousWatchdogAgent())->run($params);
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'agent' => 'agent_handoff', 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
}

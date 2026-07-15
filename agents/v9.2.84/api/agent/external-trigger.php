<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

$root = dirname(__DIR__, 2);
foreach ([$root . '/app/Support.php', $root . '/vendor/autoload.php'] as $file) {
    if (is_file($file)) require_once $file;
}

require_once $root . '/app/MediaQualityAgent.php';
require_once $root . '/app/AutonomousReportAgent.php';

try {
    $task = $_REQUEST['task'] ?? 'report';
    if ($task === 'media') {
        $result = (new ShopvivalizMediaQualityAgent())->run($_REQUEST);
    } else {
        $result = (new ShopvivalizAutonomousReportAgent())->run($_REQUEST);
    }
    echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'agent' => 'external_trigger', 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
}

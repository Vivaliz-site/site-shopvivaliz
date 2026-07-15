<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

$root = dirname(__DIR__, 2);
foreach ([$root . '/app/Support.php', $root . '/vendor/autoload.php'] as $file) {
    if (is_file($file)) require_once $file;
}

require_once $root . '/app/MediaMismatchAgent.php';

try {
    $agent = new ShopvivalizMediaMismatchAgent();
    echo json_encode($agent->run($_REQUEST), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'agent' => 'media_mismatch', 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
}

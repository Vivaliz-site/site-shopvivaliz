<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

$root = dirname(__DIR__, 2);
$autoloads = [$root . '/app/Support.php', $root . '/vendor/autoload.php'];
foreach ($autoloads as $file) {
    if (is_file($file)) require_once $file;
}

require_once $root . '/app/Olist198SyncAgent.php';

try {
    $agent = new ShopvivalizOlist198SyncAgent();
    echo json_encode($agent->run($_REQUEST), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'agent' => 'olist_198_sync', 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
}
?>

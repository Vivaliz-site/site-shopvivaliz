<?php
declare(strict_types=1);

require_once __DIR__ . '/../api/ml/client.php';
require_once __DIR__ . '/../api/ml/order-sync.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(405);
    echo "CLI only\n";
    exit(1);
}

$logPath = ml_root() . '/logs/ml-webhooks.log';
if (!is_file($logPath)) {
    fwrite(STDERR, "ml-webhooks.log not found\n");
    exit(1);
}

$seen = [];
$processed = 0;
$failed = 0;

$fh = fopen($logPath, 'r');
if (!$fh) {
    fwrite(STDERR, "cannot open ml-webhooks.log\n");
    exit(1);
}

while (($line = fgets($fh)) !== false) {
    $row = json_decode($line, true);
    if (!is_array($row)) {
        continue;
    }
    $topic = (string)($row['topic'] ?? '');
    $resource = trim((string)($row['resource'] ?? ''));
    if ($topic !== 'orders_v2' || $resource === '' || isset($seen[$resource])) {
        continue;
    }
    $seen[$resource] = true;

    try {
        ml_sync_order_from_webhook($resource);
        $processed++;
        fwrite(STDOUT, "[OK] {$resource}\n");
    } catch (Throwable $e) {
        $failed++;
        fwrite(STDERR, "[ERR] {$resource} :: {$e->getMessage()}\n");
    }
}
fclose($fh);

fwrite(STDOUT, json_encode([
    'ok' => $failed === 0,
    'processed' => $processed,
    'failed' => $failed,
    'unique_order_resources' => count($seen),
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL);

#!/usr/bin/env php
<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/integration-health.php';

$interval = 900;
$once = false;
for ($i = 1, $count = count($argv); $i < $count; $i++) {
    if ($argv[$i] === '--once') {
        $once = true;
        continue;
    }
    if ($argv[$i] === '--interval' && isset($argv[$i + 1])) {
        $interval = max(60, (int)$argv[++$i]);
    }
}

do {
    $result = svih_ml(true);
    $status = (string)($result['status'] ?? 'failed');
    $providerStatus = (int)($result['provider_status'] ?? 0);
    $fixCount = count((array)($result['fixes'] ?? []));
    echo json_encode([
        'component' => 'mercado_livre_token_renewer',
        'status' => $status,
        'provider_status' => $providerStatus,
        'refreshes' => $fixCount,
        'checked_at' => gmdate(DATE_ATOM),
    ], JSON_UNESCAPED_SLASHES) . PHP_EOL;

    if ($once) {
        exit($status === 'connected' ? 0 : 1);
    }

    $sleepFor = $status === 'connected' ? $interval : min(300, $interval);
    sleep($sleepFor);
} while (true);

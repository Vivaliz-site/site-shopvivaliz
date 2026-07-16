<?php
/**
 * ShopVivaliz update executor.
 *
 * Usage:
 *   php tools/execute-update.php /path/to/shopvivaliz-v9270.zip 9.2.70-update-executor-agent
 *
 * Env:
 *   SHOPVIVALIZ_UPDATE_URL=https://dev.shopvivaliz.com.br/installer/update.php
 *   SHOPVIVALIZ_UPDATE_TOKEN=optional-token
 */
$zip = $argv[1] ?? '';
$expectedVersion = $argv[2] ?? getenv('SHOPVIVALIZ_EXPECTED_VERSION') ?: '';
$updateUrl = getenv('SHOPVIVALIZ_UPDATE_URL') ?: 'https://dev.shopvivaliz.com.br/installer/update.php';
$token = getenv('SHOPVIVALIZ_UPDATE_TOKEN') ?: '';
$timeout = (int)(getenv('SHOPVIVALIZ_UPDATE_TIMEOUT') ?: 300);

if ($zip === '' || !is_file($zip)) {
    fwrite(STDERR, "ZIP nao encontrado: {$zip}\n");
    exit(2);
}

function request_url(string $url, int $timeout, array $post = []): array {
    $ch = curl_init($url);
    $headers = ['User-Agent: ShopVivalizUpdateExecutor/1.0'];
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 3,
        CURLOPT_CONNECTTIMEOUT => 30,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_HEADER => true,
        CURLOPT_HTTPHEADER => $headers,
    ]);
    if ($post) {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $post);
    }
    $raw = curl_exec($ch);
    $err = curl_error($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $headerSize = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $time = (float)curl_getinfo($ch, CURLINFO_TOTAL_TIME);
    curl_close($ch);
    return [
        'status' => $status,
        'body' => $raw === false ? '' : substr($raw, $headerSize),
        'error' => $err,
        'time_seconds' => round($time, 3),
    ];
}

$post = [
    'zip' => new CURLFile($zip, 'application/zip', basename($zip)),
];
if ($token !== '') {
    $post['token'] = $token;
}

$steps = [];
$steps['upload'] = request_url($updateUrl, $timeout, $post);
$steps['finalize'] = request_url($updateUrl . (strpos($updateUrl, '?') === false ? '?' : '&') . 'finalize=1', $timeout);

$base = preg_replace('#/installer/update\.php.*$#', '', $updateUrl);
$checks = [
    'update_applied' => $base . '/installer/update-applied-check.php',
    'self_test' => $base . '/installer/self-test.php',
    'auto_routines' => $base . '/installer/auto-routines.php?expected=200&limit=50',
];
foreach ($checks as $name => $url) {
    $steps[$name] = request_url($url, $timeout);
    $json = json_decode($steps[$name]['body'], true);
    if (is_array($json)) {
        $steps[$name]['json'] = $json;
        $steps[$name]['body'] = '[json omitted]';
    }
}

$ok = true;
foreach ($steps as $name => $step) {
    if (($step['status'] ?? 0) >= 500 || ($step['status'] ?? 0) === 0) {
        $ok = false;
    }
}
$appliedVersion = $steps['update_applied']['json']['version'] ?? null;
if ($expectedVersion !== '' && $appliedVersion !== $expectedVersion) {
    $ok = false;
}

$report = [
    'ok' => $ok,
    'expected_version' => $expectedVersion,
    'applied_version' => $appliedVersion,
    'zip' => basename($zip),
    'checked_at' => date('c'),
    'steps' => $steps,
];

echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($ok ? 0 : 1);

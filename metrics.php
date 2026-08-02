<?php

declare(strict_types=1);

header('Content-Type: text/plain; version=0.0.4; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');
header_remove('X-Powered-By');

$root = __DIR__;

function sv_metrics_include_json(string $file): array
{
    $previousCode = http_response_code();
    http_response_code(200);

    ob_start();
    try {
        require $file;
    } catch (Throwable $e) {
        ob_end_clean();
        http_response_code($previousCode ?: 200);
        return ['ok' => false, 'http_code' => 500];
    }

    $body = trim((string) ob_get_clean());
    $code = http_response_code();
    http_response_code($previousCode ?: 200);

    $payload = json_decode($body, true);
    if (!is_array($payload)) {
        return ['ok' => false, 'http_code' => $code ?: 500];
    }

    $payload['http_code'] = $code ?: 200;
    return $payload;
}

$checks = [
    'version' => sv_metrics_include_json($root . '/api/health/version.php'),
    'orders' => sv_metrics_include_json($root . '/api/orders/health.php'),
    'olist' => sv_metrics_include_json($root . '/api/olist/webhook-health.php'),
];

$overallOk = true;
foreach ($checks as $check) {
    $overallOk = $overallOk && ($check['ok'] ?? false) === true && (int)($check['http_code'] ?? 500) < 500;
}

$lines = [
    '# HELP shopvivaliz_health_ok Overall production health status, 1 for healthy and 0 for unhealthy.',
    '# TYPE shopvivaliz_health_ok gauge',
    'shopvivaliz_health_ok ' . ($overallOk ? '1' : '0'),
    '# HELP shopvivaliz_dependency_health_ok Dependency health status by component, 1 for healthy and 0 for unhealthy.',
    '# TYPE shopvivaliz_dependency_health_ok gauge',
];

foreach ($checks as $name => $check) {
    $isOk = (($check['ok'] ?? false) === true && (int)($check['http_code'] ?? 500) < 500) ? '1' : '0';
    $lines[] = 'shopvivaliz_dependency_health_ok{component="' . $name . '"} ' . $isOk;
}

$lines[] = '# HELP shopvivaliz_metrics_generated_at Unix timestamp when metrics were generated.';
$lines[] = '# TYPE shopvivaliz_metrics_generated_at gauge';
$lines[] = 'shopvivaliz_metrics_generated_at ' . time();

http_response_code(200);
header('Content-Type: text/plain; version=0.0.4; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');
echo implode("\n", $lines) . "\n";

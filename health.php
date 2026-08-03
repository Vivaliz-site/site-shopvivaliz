<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');

$root = __DIR__;

function sv_health_include_json(string $file): array
{
    $previousCode = http_response_code();
    http_response_code(200);

    ob_start();
    try {
        require $file;
    } catch (Throwable $e) {
        ob_end_clean();
        http_response_code($previousCode ?: 200);
        return [
            'ok' => false,
            'http_code' => 500,
            'error' => 'health_check_exception',
        ];
    }

    $body = trim((string) ob_get_clean());
    $code = http_response_code();
    http_response_code($previousCode ?: 200);

    $payload = json_decode($body, true);
    if (!is_array($payload)) {
        return [
            'ok' => false,
            'http_code' => $code ?: 500,
            'error' => 'invalid_health_payload',
        ];
    }

    $payload['http_code'] = $code ?: 200;
    return $payload;
}

$checks = [
    'version' => sv_health_include_json($root . '/api/health/version.php'),
    'orders' => sv_health_include_json($root . '/api/orders/health.php'),
    'olist' => sv_health_include_json($root . '/api/olist/webhook-health.php'),
];

$ok = true;
foreach ($checks as $check) {
    $ok = $ok && ($check['ok'] ?? false) === true && (int) ($check['http_code'] ?? 500) < 500;
}

http_response_code($ok ? 200 : 503);
echo json_encode(
    [
        'ok' => $ok,
        'service' => 'shopvivaliz',
        'release_sha' => $checks['version']['release_sha'] ?? null,
        'checks' => $checks,
        'checked_at' => gmdate('c'),
    ],
    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
);

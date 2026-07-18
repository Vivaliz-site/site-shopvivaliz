<?php
declare(strict_types=1);

header_remove('X-Powered-By');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

function svor_root(): string
{
    return dirname(__DIR__, 2);
}

function svor_json(int $status, array $payload): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
    exit;
}

function svor_load_runtime_secrets(): void
{
    static $loaded = false;
    if ($loaded) {
        return;
    }
    $loaded = true;

    $path = svor_root() . '/config/runtime-secrets.php';
    if (!is_file($path) || !is_readable($path)) {
        return;
    }

    $secrets = require $path;
    if (!is_array($secrets)) {
        return;
    }

    foreach ($secrets as $key => $value) {
        if (!is_string($key) || $key === '' || getenv($key) !== false) {
            continue;
        }
        $stringValue = is_scalar($value) ? (string) $value : '';
        putenv($key . '=' . $stringValue);
        $_ENV[$key] = $stringValue;
        $_SERVER[$key] = $stringValue;
    }
}

function svor_env(string ...$keys): string
{
    svor_load_runtime_secrets();

    foreach ($keys as $key) {
        $value = getenv($key);
        if (is_string($value) && $value !== '') {
            return $value;
        }
        if (isset($_ENV[$key]) && is_string($_ENV[$key]) && $_ENV[$key] !== '') {
            return $_ENV[$key];
        }
    }

    return '';
}

function svor_is_local_request(): bool
{
    $remote = trim((string)($_SERVER['REMOTE_ADDR'] ?? ''));
    $host = trim((string)($_SERVER['HTTP_HOST'] ?? ''));
    return in_array($remote, ['127.0.0.1', '::1', 'localhost'], true)
        || str_starts_with($host, '127.0.0.1')
        || str_starts_with($host, 'localhost');
}

$expectedKey = svor_env('SHOPVIVALIZ_AGENT_KEY', 'AGENT_KEY', 'WATCHDOG_AGENT_KEY', 'AUTONOMOUS_AGENT_KEY');
$providedKey = trim((string) ($_GET['agent_key'] ?? $_SERVER['HTTP_X_AGENT_KEY'] ?? ''));

if ($expectedKey === '' && !svor_is_local_request()) {
    svor_json(503, [
        'ok' => false,
        'error' => 'agent_key_not_configured',
    ]);
}

if ($expectedKey !== '' && ($providedKey === '' || !hash_equals($expectedKey, $providedKey))) {
    svor_json(403, [
        'ok' => false,
        'error' => 'invalid_agent_key',
    ]);
}

clearstatcache(true);
$opcacheAvailable = function_exists('opcache_reset');
$resetResult = $opcacheAvailable ? opcache_reset() : false;

svor_json($resetResult ? 200 : 202, [
    'ok' => $resetResult,
    'opcache_available' => $opcacheAvailable,
    'reset_result' => $resetResult,
    'timestamp' => date('c'),
]);

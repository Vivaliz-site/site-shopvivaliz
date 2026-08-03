<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

$root = dirname(__DIR__, 2);
require_once $root . '/includes/runtime-env-reader.php';

$diagnostics = [
    'ok' => true,
    'service' => 'olist-webhook-health',
    'generated_at' => date('c'),
    'checks' => [],
];

$processor = __DIR__ . '/webhook-processor.php';
$diagnostics['checks']['processor_file_exists'] = is_file($processor) && is_readable($processor);

$logDir = $root . '/logs';
$diagnostics['checks']['logs_writable'] = is_dir($logDir) && is_writable($logDir);

$db = null;
try {
    $database = svre_database_config($root);
    $host = $database['host'];
    $port = (int)$database['port'];
    $name = $database['name'];
    $user = $database['user'];
    $pass = $database['pass'];

    if ($name === '' || $user === '') {
        throw new RuntimeException('database_configuration_missing');
    }
    if (!class_exists('mysqli') || !function_exists('mysqli_report')) {
        throw new RuntimeException('mysqli_extension_unavailable');
    }

    mysqli_report(MYSQLI_REPORT_OFF);
    $db = @new mysqli($host, $user, $pass, $name, $port);
    if ($db->connect_errno !== 0) {
        throw new RuntimeException('database_connection_failed');
    }
    $db->set_charset('utf8mb4');
    $probe = $db->query('SELECT 1');
    if ($probe === false) {
        throw new RuntimeException('database_probe_failed');
    }
    $probe->free();

    $diagnostics['checks']['database_connected'] = true;
} catch (Throwable $e) {
    $diagnostics['checks']['database_connected'] = false;
    $diagnostics['checks']['database_error'] = 'Runtime database configuration unavailable or connection failed';
} finally {
    if ($db instanceof mysqli) {
        $db->close();
    }
}

$diagnostics['ok'] = $diagnostics['checks']['processor_file_exists']
    && $diagnostics['checks']['logs_writable']
    && $diagnostics['checks']['database_connected'];

http_response_code($diagnostics['ok'] ? 200 : 503);
echo json_encode(
    $diagnostics,
    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT
);

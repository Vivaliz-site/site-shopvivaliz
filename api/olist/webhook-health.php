<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

require_once dirname(__DIR__, 2) . '/includes/product-price-enrich.php';

$diagnostics = [
    'ok' => true,
    'service' => 'olist-webhook-health',
    'generated_at' => date('c'),
    'checks' => []
];

$processor = __DIR__ . '/webhook-processor.php';
$diagnostics['checks']['processor_file_exists'] = is_file($processor) && is_readable($processor);

$logDir = dirname(__DIR__, 2) . '/logs';
$diagnostics['checks']['logs_writable'] = is_dir($logDir) && is_writable($logDir);

try {
    $db = svp_db();
    if ($db instanceof mysqli) {
        $diagnostics['checks']['database_connected'] = true;
        $db->close();
    } else {
        $diagnostics['checks']['database_connected'] = false;
        $diagnostics['checks']['database_error'] = 'Runtime database configuration unavailable or connection failed';
    }
} catch (Throwable $e) {
    $diagnostics['checks']['database_connected'] = false;
    $diagnostics['checks']['database_error'] = $e->getMessage();
}

$diagnostics['ok'] = $diagnostics['checks']['processor_file_exists'] &&
                     $diagnostics['checks']['logs_writable'] &&
                     $diagnostics['checks']['database_connected'];

http_response_code($diagnostics['ok'] ? 200 : 503);
echo json_encode($diagnostics, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);

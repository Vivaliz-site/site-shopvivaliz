<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

$diagnostics = [
    'ok' => true,
    'service' => 'olist-webhook-health',
    'generated_at' => date('c'),
    'checks' => []
];

// 1. Verificar arquivo do processador
$processor = __DIR__ . '/webhook-processor.php';
$diagnostics['checks']['processor_file_exists'] = is_file($processor) && is_readable($processor);

// 2. Verificar permissão de escrita nos logs
$logDir = dirname(__DIR__, 2) . '/logs';
$diagnostics['checks']['logs_writable'] = is_dir($logDir) && is_writable($logDir);

// 3. Tentar conectar ao banco de dados
try {
    // The active Oracle release keeps production secrets in the shared/runtime
    // configuration. Do not fall back to repository-local root credentials.
    $constants_file = dirname(__DIR__, 2) . '/config/constants.php';
    if (is_file($constants_file) && is_readable($constants_file)) {
        require_once $constants_file;
    }

    $db_host = getenv('DB_HOST') ?: (defined('DB_HOST') ? DB_HOST : 'localhost');
    $db_user = getenv('DB_USER') ?: (defined('DB_USER') ? DB_USER : '');
    $db_pass = getenv('DB_PASS') ?: (defined('DB_PASS') ? DB_PASS : '');
    $db_name = getenv('DB_NAME') ?: (defined('DB_NAME') ? DB_NAME : '');
    $db_port = (int)(getenv('DB_PORT') ?: (defined('DB_PORT') ? DB_PORT : 3306));

    if (!class_exists('mysqli')) {
        throw new RuntimeException('ext-mysqli indisponivel');
    }

    $db = @new mysqli($db_host, $db_user, $db_pass, $db_name, $db_port);
    if ($db && !$db->connect_error) {
        $diagnostics['checks']['database_connected'] = true;
        $db->close();
    } else {
        $diagnostics['checks']['database_connected'] = false;
        $diagnostics['checks']['database_error'] = $db->connect_error ?? 'Erro desconhecido';
    }
} catch (Exception $e) {
    $diagnostics['checks']['database_connected'] = false;
    $diagnostics['checks']['database_error'] = $e->getMessage();
}

// 4. Verificar status geral
$diagnostics['ok'] = $diagnostics['checks']['processor_file_exists'] &&
                     $diagnostics['checks']['logs_writable'] &&
                     $diagnostics['checks']['database_connected'];

http_response_code($diagnostics['ok'] ? 200 : 503);
echo json_encode($diagnostics, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
?>

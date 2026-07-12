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
    $env_file = dirname(__DIR__, 2) . '/.env';
    $db_host = 'localhost';
    $db_user = 'root';
    $db_pass = '';
    $db_name = 'shopvivaliz';

    if (is_file($env_file)) {
        foreach (file($env_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) continue;
            [$k, $v] = explode('=', $line, 2);
            $key = trim($k);
            $value = trim(trim($v), '"\'');
            if ($key === 'DB_HOST') $db_host = $value;
            if ($key === 'DB_USER') $db_user = $value;
            if ($key === 'DB_PASS') $db_pass = $value;
            if ($key === 'DB_NAME') $db_name = $value;
        }
    }

    $db = @new mysqli($db_host, $db_user, $db_pass, $db_name);
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

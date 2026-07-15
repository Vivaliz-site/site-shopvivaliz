<?php
/**
 * Endpoint de Verificação de Saúde (Health Check) do ShopVivaliz
 *
 * Retorna o status do sistema, incluindo conexão com o banco de dados e
 * configurações essenciais.
 */

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('X-XSS-Protection: 1; mode=block');

// Em um ambiente de produção, considere um HSTS mais longo após testes.
// header('Strict-Transport-Security: max-age=31536000; includeSubDomains');

try {
    // Carrega as constantes e a configuração do banco de dados de forma segura
require_once __DIR__ . '/config/constants.php';
require_once __DIR__ . '/config/database.php';

    // Tenta obter uma instância do banco de dados
    $db_instance = Database::getInstance();
    $connection = $db_instance->getConnection();

    $db_status = 'ok';
    $db_error = null;

    if (!$connection || $connection->connect_error) {
        $db_status = 'error';
        $db_error = $connection ? $connection->connect_error : 'Falha ao obter conexão.';
        http_response_code(503); // Service Unavailable
    }

    // Verifica se diretórios essenciais para a operação existem e têm permissão de escrita
    $logs_writable = is_writable(LOGS_PATH);
    $uploads_writable = is_writable(UPLOADS_PATH);

    $storage_status = ($logs_writable && $uploads_writable) ? 'ok' : 'error';

    if ($storage_status === 'error') {
        http_response_code(503);
    }

    $response = [
        'ok' => ($db_status === 'ok' && $storage_status === 'ok'),
        'status' => ($db_status === 'ok' && $storage_status === 'ok') ? 'healthy' : 'degraded',
        'timestamp' => date('c'),
        'version' => defined('APP_VERSION') ? APP_VERSION : 'unknown',
        'environment' => ENVIRONMENT,
        'checks' => [
            'database' => ['status' => $db_status, 'error' => DEBUG_MODE ? $db_error : null],
            'storage' => [
                'status' => $storage_status,
                'logs_writable' => $logs_writable,
                'uploads_writable' => $uploads_writable,
            ],
        ],
    ];

} catch (Exception $e) {
    http_response_code(500); // Internal Server Error
    $response = [
        'ok' => false,
        'status' => 'error',
        'message' => 'Uma exceção crítica ocorreu durante a verificação de saúde.',
        'error' => DEBUG_MODE ? $e->getMessage() : 'Detalhes ocultos em ambiente de produção.',
    ];
}

echo json_encode($response, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
exit;

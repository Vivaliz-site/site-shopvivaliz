<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/admin-guard.php';
header_remove('X-Powered-By');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$host = getenv('DB_HOST') ?: 'localhost';
$user = getenv('DB_USER') ?: 'root';
$pass = getenv('DB_PASS') ?: '';
$db_name = getenv('DB_NAME') ?: 'shopvivaliz';

$db = new mysqli($host, $user, $pass, $db_name, 3306);

if ($db->connect_error) {
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'erro' => 'Falha ao conectar ao banco',
        'detalhe_tecnico' => $db->connect_error,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// Conexão OK - contar produtos
$result = $db->query("SELECT COUNT(*) as total FROM products");
$queryError = $db->error;

if (!$result) {
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'erro' => 'Falha ao consultar produtos',
        'detalhe_tecnico' => $queryError,
    ], JSON_UNESCAPED_UNICODE);
    $db->close();
    exit;
}

$row = $result->fetch_assoc();
$total = $row['total'] ?? 0;

echo json_encode([
    'ok' => true,
    'conexao' => 'sucesso',
    'produtos' => $total,
    'ambiente' => [
        'database' => $db_name,
        'server_version' => $db->server_info,
    ],
], JSON_UNESCAPED_UNICODE);

$db->close();
?>

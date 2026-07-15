<?php
require_once __DIR__ . '/../includes/admin-guard.php';
header('Content-Type: application/json; charset=utf-8');

$host = getenv('DB_HOST') ?: 'localhost';
$user = getenv('DB_USER') ?: 'root';
$pass = getenv('DB_PASS') ?: '';
$db_name = getenv('DB_NAME') ?: 'shopvivaliz';

$db = new mysqli($host, $user, $pass, $db_name, 3306);

if ($db->connect_error) {
    echo json_encode([
        'ok' => false,
        'erro' => $db->connect_error,
        'credenciais_testadas' => [
            'host' => $host,
            'user' => $user,
            'db' => $db_name
        ]
    ]);
    exit;
}

// Conexão OK - contar produtos
$result = $db->query("SELECT COUNT(*) as total FROM products");
$row = $result->fetch_assoc();
$total = $row['total'] ?? 0;

echo json_encode([
    'ok' => true,
    'conexao' => 'sucesso',
    'produtos' => $total,
    'host' => $host,
    'user' => $user,
    'db' => $db_name
]);

$db->close();
?>

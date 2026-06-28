<?php
header('Content-Type: application/json; charset=utf-8');

$host = 'localhost';
$user = 'shopv506_claude';
$pass = 'CFqmkF8}$C_2';
$db_name = 'shopv506_shopvivaliz';

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

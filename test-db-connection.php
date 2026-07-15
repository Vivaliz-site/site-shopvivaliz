<?php
header('Content-Type: application/json; charset=utf-8');

// TESTA CONEXÃO COM AS CREDENCIAIS CORRETAS
$host = getenv('DB_HOST') ?: 'localhost';
$user = getenv('DB_USER') ?: 'shopv506_user';
$pass = getenv('DB_PASS') ?: '';
$db = getenv('DB_NAME') ?: 'shopv506_shopvivaliz';

try {
    $conn = new mysqli($host, $user, $pass, $db);

    if ($conn->connect_error) {
        exit(json_encode([
            'ok' => false,
            'erro' => $conn->connect_error,
            'config_tentada' => [
                'host' => $host,
                'user' => $user,
                'db' => $db
            ]
        ]));
    }

    // CONEXÃO BEM-SUCEDIDA!
    $result = $conn->query('SELECT COUNT(*) as total FROM products LIMIT 1');
    $row = $result->fetch_assoc();
    $total = $row['total'] ?? 0;

    $conn->close();

    echo json_encode([
        'ok' => true,
        'conexao' => 'sucesso',
        'config' => [
            'host' => $host,
            'user' => $user,
            'db' => $db
        ],
        'total_produtos' => $total,
        'timestamp' => date('c')
    ]);

} catch (Exception $e) {
    exit(json_encode([
        'ok' => false,
        'erro' => $e->getMessage(),
        'config_tentada' => [
            'host' => $host,
            'user' => $user,
            'db' => $db
        ]
    ]));
}
?>

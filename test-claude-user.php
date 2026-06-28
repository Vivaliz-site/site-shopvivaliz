<?php
header('Content-Type: application/json; charset=utf-8');

// Testa se "claude" é usuário MySQL válido
$configs = [
    ['host' => 'localhost', 'user' => 'claude', 'pass' => 'CFqmkF8}$C_2', 'db' => 'shopv506_shopvivaliz'],
    ['host' => 'localhost', 'user' => 'claude', 'pass' => 'CFqmkF8}$C_2', 'db' => 'shopvivaliz'],
    ['host' => '127.0.0.1', 'user' => 'claude', 'pass' => 'CFqmkF8}$C_2', 'db' => 'shopv506_shopvivaliz'],
];

$results = [];

foreach ($configs as $cfg) {
    $key = "{$cfg['user']}@{$cfg['host']}/{$cfg['db']}";

    try {
        $conn = @new mysqli($cfg['host'], $cfg['user'], $cfg['pass'], $cfg['db']);

        if (!$conn->connect_error) {
            $result = $conn->query('SELECT COUNT(*) as total FROM products LIMIT 1');

            if ($result) {
                $row = $result->fetch_assoc();
                $results[$key] = [
                    'status' => 'SUCESSO',
                    'total_produtos' => $row['total'] ?? 0,
                    'config' => $cfg
                ];
            }
            $conn->close();
        } else {
            $results[$key] = [
                'status' => 'ERRO_CONEXAO',
                'erro' => $conn->connect_error
            ];
        }
    } catch (Exception $e) {
        $results[$key] = ['status' => 'EXCEÇÃO', 'erro' => $e->getMessage()];
    }
}

echo json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
?>

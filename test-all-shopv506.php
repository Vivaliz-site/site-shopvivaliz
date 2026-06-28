<?php
header('Content-Type: application/json; charset=utf-8');

// Testa TODAS as variações de shopv506
$configs = [
    // Usuários possíveis
    ['host' => 'localhost', 'user' => 'shopv506_user', 'pass' => '', 'db' => 'shopv506_shopvivaliz'],
    ['host' => 'localhost', 'user' => 'shopv506_user', 'pass' => 'shopv506', 'db' => 'shopv506_shopvivaliz'],
    ['host' => 'localhost', 'user' => 'shopv506', 'pass' => '', 'db' => 'shopv506_shopvivaliz'],
    ['host' => 'localhost', 'user' => 'shopv506', 'pass' => 'shopv506', 'db' => 'shopv506_shopvivaliz'],
    ['host' => 'localhost', 'user' => 'shopv506_dev', 'pass' => '', 'db' => 'shopv506_shopvivaliz'],
    ['host' => 'localhost', 'user' => 'shopv506_dev', 'pass' => 'shopv506', 'db' => 'shopv506_shopvivaliz'],

    // Nomes de banco alternativos
    ['host' => 'localhost', 'user' => 'shopv506_user', 'pass' => '', 'db' => 'shopv506_dev'],
    ['host' => 'localhost', 'user' => 'shopv506_user', 'pass' => '', 'db' => 'shopvivaliz'],
    ['host' => 'localhost', 'user' => 'root', 'pass' => '', 'db' => 'shopv506_shopvivaliz'],

    // Com 127.0.0.1
    ['host' => '127.0.0.1', 'user' => 'shopv506_user', 'pass' => '', 'db' => 'shopv506_shopvivaliz'],
];

$results = [];
$found = false;

foreach ($configs as $cfg) {
    $key = "{$cfg['user']}@{$cfg['host']}/{$cfg['db']}";

    try {
        $conn = @new mysqli($cfg['host'], $cfg['user'], $cfg['pass'], $cfg['db']);

        if (!$conn->connect_error) {
            $result = $conn->query('SELECT COUNT(*) as total FROM products LIMIT 1');

            if ($result) {
                $row = $result->fetch_assoc();
                $total = $row['total'] ?? 0;

                $results[$key] = [
                    'status' => 'OK',
                    'total_products' => $total
                ];

                if (!$found) {
                    $found = true;
                    $results['PRIMEIRA_CONEXAO_BEM_SUCEDIDA'] = $key;
                }
            }
            $conn->close();
        } else {
            $results[$key] = [
                'status' => 'ERRO',
                'erro' => $conn->connect_error
            ];
        }
    } catch (Exception $e) {
        $results[$key] = [
            'status' => 'EXCEÇÃO',
            'erro' => $e->getMessage()
        ];
    }
}

echo json_encode([
    'testadas' => count($configs),
    'sucesso_encontrada' => $found,
    'resultados' => $results,
    'timestamp' => date('c')
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
?>

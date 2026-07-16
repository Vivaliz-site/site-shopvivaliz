<?php
// Diagnóstico de banco de dados - tenta TODAS as configurações possíveis
header('Content-Type: application/json; charset=utf-8');

$results = [
    'tentativas' => 0,
    'sucesso' => false,
    'total_produtos' => 0,
    'conexoes_testadas' => []
];

// LISTA DE TODAS AS COMBINAÇÕES POSSÍVEIS
$configs = [
    // Padrão localhost
    ['host' => 'localhost', 'user' => 'root', 'pass' => '', 'db' => 'shopvivaliz'],
    ['host' => 'localhost', 'user' => 'root', 'pass' => 'root', 'db' => 'shopvivaliz'],
    ['host' => 'localhost', 'user' => 'root', 'pass' => 'password', 'db' => 'shopvivaliz'],

    // shopvivaliz user
    ['host' => 'localhost', 'user' => 'shopvivaliz', 'pass' => '', 'db' => 'shopvivaliz'],
    ['host' => 'localhost', 'user' => 'shopvivaliz', 'pass' => 'shopvivaliz', 'db' => 'shopvivaliz'],

    // shopv506 user (HostGator)
    ['host' => 'localhost', 'user' => 'shopv506_user', 'pass' => '', 'db' => 'shopv506_dev'],
    ['host' => 'localhost', 'user' => 'shopv506_user', 'pass' => 'shopv506', 'db' => 'shopv506_dev'],
    ['host' => 'localhost', 'user' => 'shopv506', 'pass' => '', 'db' => 'shopv506_dev'],

    // 127.0.0.1
    ['host' => '127.0.0.1', 'user' => 'root', 'pass' => '', 'db' => 'shopvivaliz'],
    ['host' => '127.0.0.1', 'user' => 'shopvivaliz', 'pass' => '', 'db' => 'shopvivaliz'],

    // MySQL socket
    ['host' => ':/tmp/mysql.sock', 'user' => 'root', 'pass' => '', 'db' => 'shopvivaliz'],
];

foreach ($configs as $cfg) {
    $results['tentativas']++;
    $h = $cfg['host'];
    $u = $cfg['user'];
    $p = $cfg['pass'];
    $d = $cfg['db'];

    try {
        $conn = @new mysqli($h, $u, $p, $d);

        if (!$conn->connect_error) {
            // CONECTOU! Agora contar produtos
            $result = $conn->query('SELECT COUNT(*) as total FROM products LIMIT 1');

            if ($result) {
                $row = $result->fetch_assoc();
                $total = $row['total'] ?? 0;

                $results['sucesso'] = true;
                $results['total_produtos'] = $total;
                $results['conexao_bem_sucedida'] = [
                    'host' => $h,
                    'user' => $u,
                    'pass' => strlen($p) > 0 ? '***' : '(vazio)',
                    'db' => $d
                ];

                $conn->close();
                break;
            }

            $conn->close();
        }
    } catch (Exception $e) {
        $results['conexoes_testadas'][] = "$u@$h = " . $e->getMessage();
    }
}

if (!$results['sucesso']) {
    $results['erro'] = 'Nenhuma configuração de banco funcionou';
    $results['testadas'] = count($configs);
}

echo json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
?>

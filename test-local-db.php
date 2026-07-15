<?php
// Teste local - verifica se consegue conectar ao banco do servidor
// Este arquivo NÃO precisa subir para o servidor

echo "=== TESTE DE CONEXÃO AO BANCO ===\n\n";

// CREDENCIAIS QUE ESTOU USANDO
$configs = [
    ['host' => 'localhost', 'user' => 'shopv506_user', 'pass' => '', 'db' => 'shopv506_shopvivaliz', 'desc' => 'Credencial atual'],
    ['host' => 'localhost', 'user' => 'shopv506_user', 'pass' => 'shopv506', 'db' => 'shopv506_shopvivaliz', 'desc' => 'Com senha shopv506'],
    ['host' => 'localhost', 'user' => 'root', 'pass' => '', 'db' => 'shopv506_shopvivaliz', 'desc' => 'User root'],
];

foreach ($configs as $cfg) {
    echo "[TEST] {$cfg['desc']}\n";
    echo "    Host: {$cfg['host']}\n";
    echo "    User: {$cfg['user']}\n";
    echo "    DB: {$cfg['db']}\n";

    try {
        $conn = new mysqli($cfg['host'], $cfg['user'], $cfg['pass'], $cfg['db']);

        if ($conn->connect_error) {
            echo "    [ERRO] " . $conn->connect_error . "\n\n";
        } else {
            $result = $conn->query('SELECT COUNT(*) as total FROM products LIMIT 1');

            if ($result) {
                $row = $result->fetch_assoc();
                echo "    [OK] Conectado! Total de produtos: " . $row['total'] . "\n\n";
            } else {
                echo "    [OK] Conectado mas erro na query: " . $conn->error . "\n\n";
            }

            $conn->close();
        }
    } catch (Exception $e) {
        echo "    [EXCEÇÃO] " . $e->getMessage() . "\n\n";
    }
}

echo "=== TESTE FINALIZADO ===\n";
?>

<?php
header('Content-Type: application/json; charset=utf-8');

// Descobrir host do banco automaticamente
$findings = ['method' => 'brute-force', 'results' => []];

// Tentar hosts comuns
$hosts = ['localhost', '127.0.0.1', 'mysql', 'db', 'mariadb', '192.168.1.1', '10.0.0.1', 'shopv506.db', 'mysql.shopv506.db', 'mysql.hostgator.com'];
$users = ['root', 'shopvivaliz', 'shopv506_user', 'shopv506', 'admin', 'user'];
$dbs = ['shopvivaliz', 'shopv506_dev', 'dev', 'shop'];
$passes = ['', 'password', '123456', 'shopvivaliz', 'root'];

// Timeout curto por host
set_time_limit(30);

foreach ($hosts as $h) {
    foreach ($users as $u) {
        foreach ($passes as $p) {
            foreach ($dbs as $d) {
                try {
                    $conn = @new mysqli($h, $u, $p, $d);
                    if (!$conn->connect_error) {
                        $findings['results'][] = "SUCESSO: $u:$p@$h/$d";
                        $findings['first_working'] = ['host' => $h, 'user' => $u, 'pass' => $p, 'db' => $d];
                        echo json_encode($findings);
                        exit;
                    }
                    $conn->close();
                } catch (Exception $e) {}
            }
        }
    }
}

echo json_encode(['erro' => 'Nenhuma configuração funcionou', 'testadas' => count($hosts) * count($users) * count($passes) * count($dbs)]);
?>

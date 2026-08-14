<?php
declare(strict_types=1);

$t0 = microtime(true);
$results = [];

try {
    $pdo1 = new PDO('mysql:host=localhost;dbname=shopvivaliz;charset=utf8mb4', 'shopvivaliz', 'shopvivaliz123');
    $results['host_localhost'] = microtime(true) - $t0;
} catch (Throwable $e) {
    $results['host_localhost_err'] = $e->getMessage();
}

$t0 = microtime(true);
try {
    $pdo2 = new PDO('mysql:unix_socket=/var/run/mysqld/mysqld.sock;dbname=shopvivaliz;charset=utf8mb4', 'shopvivaliz', 'shopvivaliz123');
    $results['unix_socket'] = microtime(true) - $t0;
} catch (Throwable $e) {
    $results['unix_socket_err'] = $e->getMessage();
}

header('Content-Type: application/json');
echo json_encode($results, JSON_PRETTY_PRINT);

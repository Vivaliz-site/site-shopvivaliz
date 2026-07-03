<?php
header('Content-Type: application/json; charset=utf-8');

(static function() {
    $f = dirname(__DIR__) . '/.env';
    if (!is_file($f)) return;
    foreach (file($f, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) continue;
        [$k, $v] = explode('=', $line, 2);
        $k = trim($k); $v = trim(trim($v), '"\'');
        if ($k !== '' && getenv($k) === false) putenv("$k=$v");
    }
})();

$host    = getenv('DB_HOST') ?: 'localhost';
$user    = getenv('DB_USER') ?: '';
$pass    = getenv('DB_PASS') ?: '';
$db_name = getenv('DB_NAME') ?: '';

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

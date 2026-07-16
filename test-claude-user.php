<?php
header('Content-Type: application/json; charset=utf-8');

// Testa se "claude" é usuário MySQL válido
(static function() {
    $f = __DIR__ . '/.env';
    if (!is_file($f)) return;
    foreach (file($f, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) continue;
        [$k, $v] = explode('=', $line, 2);
        $k = trim($k); $v = trim(trim($v), '"\'');
        if ($k !== '' && getenv($k) === false) putenv("$k=$v");
    }
})();

$dbPass = getenv('DB_PASS') ?: '';
$dbName = getenv('DB_NAME') ?: 'shopv506_shopvivaliz';
$configs = [
    ['host' => 'localhost',  'user' => getenv('DB_USER') ?: 'claude', 'pass' => $dbPass, 'db' => $dbName],
    ['host' => 'localhost',  'user' => getenv('DB_USER') ?: 'claude', 'pass' => $dbPass, 'db' => 'shopvivaliz'],
    ['host' => '127.0.0.1', 'user' => getenv('DB_USER') ?: 'claude', 'pass' => $dbPass, 'db' => $dbName],
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

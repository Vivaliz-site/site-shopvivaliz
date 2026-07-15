<?php
// Descobrir credenciais do banco automaticamente
// Tenta: arquivo de config, variáveis de ambiente, bruteforce

$results = [];

// 1. Tentar arquivo .env
if (file_exists('.env')) {
    $env = file_get_contents('.env');
    preg_match('/DB_HOST=(.+)/i', $env, $m);
    $results['from_env_file'] = $m[1] ?? 'não encontrado';
}

// 2. Tentar arquivo wp-config.php (WordPress)
if (file_exists('wp-config.php')) {
    $results['wordpress_detectado'] = 'sim';
}

// 3. Tentar arquivo config em diferentes passos
foreach (glob('**/config*.php', GLOB_BRACE) as $file) {
    $results['config_files'][] = $file;
}

// 4. Tentar arquivo de backup/antigo
foreach (glob('**/config*.bak', GLOB_BRACE) as $file) {
    $results['config_backups'][] = $file;
}

// 5. Listar todas as variáveis de ambiente
$results['php_ini'] = ini_get('variables_order');
$results['env_vars'] = [
    'DB_HOST' => getenv('DB_HOST'),
    'DB_USER' => getenv('DB_USER'),
    'DB_PASS' => getenv('DB_PASS'),
    'DB_NAME' => getenv('DB_NAME'),
];

// 6. Tentar conexão com root:root (padrão Docker/Vagrant)
try {
    $conn = new mysqli('localhost', 'root', 'root', 'shopvivaliz');
    if (!$conn->connect_error) {
        $results['conexao_root_root'] = 'SUCESSO';
    }
} catch (Exception $e) {}

// 7. Tentar com user vazio
try {
    $conn = new mysqli('localhost', 'shopvivaliz', '', 'shopvivaliz');
    if (!$conn->connect_error) {
        $results['conexao_shopvivaliz_vazio'] = 'SUCESSO';
    }
} catch (Exception $e) {}

// 8. Tentar variáveis $_ENV
$results['_ENV'] = $_ENV['DB_HOST'] ?? 'não encontrado';

header('Content-Type: application/json; charset=utf-8');
echo json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
?>

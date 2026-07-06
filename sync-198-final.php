<?php
header('Content-Type: application/json; charset=utf-8');
set_time_limit(300);

// CREDENCIAIS VIA AMBIENTE
$host = getenv('DB_HOST') ?: 'localhost';
$port = (int) (getenv('DB_PORT') ?: 3306);
$db = getenv('DB_NAME') ?: 'shopvivaliz';
$user = getenv('DB_USER') ?: 'root';
$pass = getenv('DB_PASS') ?: '';

// CONECTAR
$conn = new mysqli($host, $user, $pass, $db, $port);

if ($conn->connect_error) {
    exit(json_encode(['ok' => false, 'erro' => 'Conexão: ' . $conn->connect_error]));
}

$conn->set_charset('utf8mb4');

// CRIAR TABELA SE NÃO EXISTIR
$create_table_sql = "CREATE TABLE IF NOT EXISTS products (
    id INT AUTO_INCREMENT PRIMARY KEY,
    product_id VARCHAR(50) UNIQUE NOT NULL,
    name VARCHAR(255) NOT NULL,
    price DECIMAL(10, 2) NOT NULL,
    description TEXT,
    category VARCHAR(100),
    stock INT DEFAULT 0,
    image_url VARCHAR(500),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_product_id (product_id),
    INDEX idx_category (category)
)";

if (!$conn->query($create_table_sql)) {
    exit(json_encode(['ok' => false, 'erro' => 'CREATE TABLE: ' . $conn->error]));
}

// CARREGAR 198 PRODUTOS
include __DIR__ . '/olist/produtos-olist-array.php';
$produtos = $GLOBALS['produtos_olist'] ?? [];

if (empty($produtos)) {
    exit(json_encode(['ok' => false, 'erro' => 'Nenhum produto carregado do arquivo']));
}

// SINCRONIZAR
$sql = "INSERT INTO products (product_id, name, price, description, category, stock, created_at, updated_at)
        VALUES (?, ?, ?, ?, ?, ?, NOW(), NOW())
        ON DUPLICATE KEY UPDATE price=VALUES(price), description=VALUES(description), category=VALUES(category), stock=VALUES(stock), updated_at=NOW()";

$stmt = $conn->prepare($sql);

if (!$stmt) {
    exit(json_encode(['ok' => false, 'erro' => 'PREPARE: ' . $conn->error]));
}

$sync = 0;
$erros = 0;

foreach ($produtos as $p) {
    $id = $p['id'] ?? '';
    $nome = $p['nome'] ?? '';
    $preco = (float)($p['preco'] ?? 0);
    $desc = $p['descricao'] ?? '';
    $cat = $p['categoria'] ?? 'Geral';
    $est = (int)($p['estoque'] ?? 0);

    if (!$id || !$nome) {
        $erros++;
        continue;
    }

    if (!$stmt->bind_param('ssdssi', $id, $nome, $preco, $desc, $cat, $est)) {
        $erros++;
        continue;
    }

    if ($stmt->execute()) {
        $sync++;
    } else {
        $erros++;
    }
}

// CONTAR TOTAL
$result = $conn->query('SELECT COUNT(*) as total FROM products');
$row = $result->fetch_assoc();
$total = $row['total'] ?? 0;

$conn->close();

echo json_encode([
    'ok' => true,
    'sincronizados' => $sync,
    'erros' => $erros,
    'total_no_banco' => $total,
    'esperado' => 198,
    'sucesso_completo' => ($total >= 198),
    'timestamp' => date('c')
], JSON_UNESCAPED_UNICODE);
?>

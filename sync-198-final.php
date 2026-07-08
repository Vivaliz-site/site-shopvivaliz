<?php
header('Content-Type: application/json; charset=utf-8');
set_time_limit(300);
ini_set('display_errors', 0);
error_reporting(0);

try {
    // Utiliza o carregador de .env e a classe de banco de dados central
    require_once __DIR__ . '/config/constants.php';
    require_once __DIR__ . '/config/database.php';
    $db = Database::getInstance();
    $conn = $db->getConnection();
    $conn->set_charset(DB_CHARSET);
} catch (Exception $e) {
    http_response_code(503); // Service Unavailable
    log_error('Falha ao inicializar a sincronização', ['error' => $e->getMessage()]);
    exit(json_encode(['ok' => false, 'erro' => 'Falha ao conectar ao banco de dados: ' . $e->getMessage()]));
}

// CRIAR TABELA SE NÃO EXISTIR
$create_table_sql = "CREATE TABLE IF NOT EXISTS products (
    id INT AUTO_INCREMENT PRIMARY KEY,
    product_id VARCHAR(50) UNIQUE NOT NULL,
    sku VARCHAR(50) UNIQUE NOT NULL,
    name VARCHAR(255) NOT NULL,
    price DECIMAL(10, 2) NOT NULL,
    description TEXT,
    category VARCHAR(100),
    stock INT DEFAULT 0,
    image_url VARCHAR(500),
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_sku (sku),
    INDEX idx_category (category)
)";

if (!$conn->query($create_table_sql)) {
    log_error('Falha ao criar tabela products', ['error' => $conn->error]);
    exit(json_encode(['ok' => false, 'erro' => 'CREATE TABLE: ' . $conn->error]));
}

// CARREGAR 198 PRODUTOS
include __DIR__ . '/olist/produtos-olist-array.php';
$produtos = $GLOBALS['produtos_olist'] ?? [];

if (empty($produtos)) {
    exit(json_encode(['ok' => false, 'erro' => 'Nenhum produto carregado do arquivo']));
}

// SINCRONIZAR
$sql = "INSERT INTO products (sku, name, price, description, stock, image_url, created_at, updated_at)
        VALUES (?, ?, ?, ?, ?, ?, NOW(), NOW())
        ON DUPLICATE KEY UPDATE name=VALUES(name), price=VALUES(price), description=VALUES(description), stock=VALUES(stock), image_url=VALUES(image_url), updated_at=NOW()";

$stmt = $conn->prepare($sql);

if (!$stmt) {
    log_error('Falha ao preparar statement de sincronização', ['error' => $conn->error]);
    exit(json_encode(['ok' => false, 'erro' => 'PREPARE: ' . $conn->error]));
}

$sync = 0;
$erros = 0;

foreach ($produtos as $p) {
    $sku = $p['id'] ?? '';
    $nome = $p['nome'] ?? 'Produto sem nome';
    $preco = (float)($p['preco'] ?? 0.0);
    $desc = $p['descricao'] ?? '';
    $cat = $p['categoria'] ?? 'Geral';
    $est = (int)($p['estoque'] ?? 0);
    $img = $p['url_imagem'] ?? '';

    if (!$sku || !$nome) {
        $erros++;
        continue;
    }

    // O tipo para 'preco' (decimal) é 'd' (double)
    if (!$stmt->bind_param('ssdssis', $sku, $nome, $preco, $desc, $cat, $est, $img)) {
        $erros++;
        log_error('Erro no bind_param', ['sku' => $sku, 'error' => $stmt->error]);
        continue;
    }

    if ($stmt->execute()) {
        $sync++;
    } else {
        $erros++;
        log_error('Erro ao executar insert/update', ['sku' => $sku, 'error' => $stmt->error]);
    }
}

$stmt->close();

// CONTAR TOTAL
$result = $conn->query('SELECT COUNT(*) as total FROM products');
$row = $result->fetch_assoc();
$total = $row['total'] ?? 0;

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

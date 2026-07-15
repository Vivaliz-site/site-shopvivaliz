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

// Delegar criação/ajuste de schema ao resolvedor central
try {
    create_tables();
} catch (Throwable $e) {
    log_error('Falha ao resolver schema central', ['error' => $e->getMessage()]);
    exit(json_encode(['ok' => false, 'erro' => 'SCHEMA_RESOLVER: ' . $e->getMessage()]));
}

// CARREGAR 198 PRODUTOS
include __DIR__ . '/olist/produtos-olist-array.php';
$produtos = $GLOBALS['produtos_olist'] ?? [];

if (empty($produtos)) {
    exit(json_encode(['ok' => false, 'erro' => 'Nenhum produto carregado do arquivo']));
}

// SINCRONIZAR
$sql = "INSERT INTO products (sku, name, price, description, category, stock, image_url, created_at, updated_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
        ON DUPLICATE KEY UPDATE name=VALUES(name), price=VALUES(price), description=VALUES(description), category=VALUES(category), stock=VALUES(stock), image_url=VALUES(image_url), updated_at=NOW()";

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

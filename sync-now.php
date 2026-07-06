<?php
// SYNC 198 PRODUTOS - ENDPOINT SIMPLES
header('Content-Type: application/json; charset=utf-8');
ini_set('display_errors', 0);

// Carregar array de produtos
$arquivo = __DIR__ . '/olist/produtos-olist-array.php';
if (!file_exists($arquivo)) {
    exit(json_encode(['erro' => 'Arquivo de produtos não encontrado']));
}

include $arquivo;
$produtos = $GLOBALS['produtos_olist'] ?? [];

if (empty($produtos)) {
    exit(json_encode(['erro' => 'Nenhum produto carregado']));
}

// Conectar ao banco - TESTE SIMPLES
$db_user = getenv('DB_USER') ?: 'root';
$db_pass = getenv('DB_PASS') ?: '';
$db_host = getenv('DB_HOST') ?: 'localhost';
$db_name = getenv('DB_NAME') ?: 'shopvivaliz';

try {
    $db = new mysqli($db_host, $db_user, $db_pass, $db_name);
    if ($db->connect_error) {
        throw new Exception('Conexão: ' . $db->connect_error);
    }
} catch (Exception $e) {
    // Tentar alternativas
    $configs = [
        ['127.0.0.1', 'root', '', 'shopvivaliz'],
        ['localhost', 'shopv506_user', '', 'shopv506_dev'],
    ];

    $db = null;
    foreach ($configs as [$h, $u, $p, $d]) {
        try {
            $conn = new mysqli($h, $u, $p, $d);
            if (!$conn->connect_error) {
                $db = $conn;
                break;
            }
        } catch (Exception $e) {}
    }

    if (!$db) {
        exit(json_encode(['erro' => 'Sem banco de dados', 'tentativas' => 3]));
    }
}

// Sincronizar
$sql = "INSERT INTO products (product_id, name, price, description, category, stock, created_at, updated_at)
VALUES (?, ?, ?, ?, ?, ?, NOW(), NOW())
ON DUPLICATE KEY UPDATE price=VALUES(price), description=VALUES(description), category=VALUES(category), stock=VALUES(stock), updated_at=NOW()";

$stmt = $db->prepare($sql);
if (!$stmt) {
    exit(json_encode(['erro' => 'SQL Error: ' . $db->error]));
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

    if (!$id || !$nome) continue;

    if ($stmt->bind_param('ssdssi', $id, $nome, $preco, $desc, $cat, $est)) {
        if ($stmt->execute()) {
            $sync++;
        } else {
            $erros++;
        }
    } else {
        $erros++;
    }
}

// Contar
$result = $db->query('SELECT COUNT(*) as total FROM products');
$row = $result->fetch_assoc();
$total = $row['total'] ?? 0;

$db->close();

echo json_encode([
    'ok' => true,
    'sincronizados' => $sync,
    'erros' => $erros,
    'total' => $total,
    'esperado' => 198,
    'sucesso' => ($total >= 190),
    'ts' => date('c')
]);
?>

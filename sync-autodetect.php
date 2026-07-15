<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);
header('Content-Type: application/json; charset=utf-8');
set_time_limit(120);

// Tentar diferentes configurações de banco
$configs = [
    ['host' => 'localhost', 'user' => 'root', 'pass' => '', 'db' => 'shopvivaliz'],
    ['host' => '127.0.0.1', 'user' => 'root', 'pass' => '', 'db' => 'shopvivaliz'],
    ['host' => 'localhost', 'user' => 'shopvivaliz', 'pass' => '', 'db' => 'shopvivaliz'],
    ['host' => '127.0.0.1', 'user' => 'shopvivaliz', 'pass' => '', 'db' => 'shopvivaliz'],
    ['host' => 'mysql', 'user' => 'root', 'pass' => '', 'db' => 'shopvivaliz'],
];

$db = null;
$config_usada = null;

// Tentar cada configuração
foreach ($configs as $cfg) {
    try {
        $conn = new mysqli($cfg['host'], $cfg['user'], $cfg['pass'], $cfg['db']);
        if (!$conn->connect_error) {
            $db = $conn;
            $config_usada = $cfg;
            break;
        }
    } catch (Exception $e) {
        continue;
    }
}

if (!$db) {
    exit(json_encode([
        'erro' => 'Não conseguiu conectar ao banco com nenhuma configuração',
        'config_testadas' => count($configs),
        'timestamp' => date('c')
    ]));
}

// Carrega catálogo real de produtos do JSON de fallback
$produtos = [];
function sv_load_catalog_products(): array
{
    $path = __DIR__ . '/api/catalog/fallback-products.json';
    if (!is_file($path)) {
        return [];
    }
    $data = json_decode((string) file_get_contents($path), true);
    if (!is_array($data)) {
        return [];
    }
    $products = [];
    foreach ($data as $item) {
        if (!is_array($item)) {
            continue;
        }
        $sku = trim((string) ($item['sku'] ?? $item['id'] ?? ''));
        if ($sku === '') {
            continue;
        }
        $products[] = [
            'id' => $sku,
            'nome' => trim((string) ($item['name'] ?? $item['nome'] ?? '')),
            'preco' => (float) ($item['price'] ?? $item['preco'] ?? 0),
            'descricao' => trim((string) ($item['description'] ?? $item['descricao'] ?? '')),
            'categoria' => trim((string) ($item['category'] ?? $item['categoria'] ?? 'Geral')),
            'estoque' => max(0, (int) ($item['stock'] ?? $item['estoque'] ?? 0)),
        ];
    }
    return $products;
}

$produtos = sv_load_catalog_products();
if ($produtos === []) {
    echo json_encode([
        'erro' => 'Não foi possível carregar o catálogo de fallback em api/catalog/fallback-products.json',
        'config_testadas' => count($configs),
        'timestamp' => date('c'),
    ]);
    exit;
}

// Inserir produtos (estendido para 198 no servidor)
$sync = 0;
$erros = 0;

foreach ($produtos as $p) {
    try {
        $sql = "INSERT INTO products (product_id, name, price, description, category, stock, created_at, updated_at)
                VALUES (?, ?, ?, ?, ?, ?, NOW(), NOW())
                ON DUPLICATE KEY UPDATE price=VALUES(price), description=VALUES(description), category=VALUES(category), stock=VALUES(stock), updated_at=NOW()";

        $stmt = $db->prepare($sql);
        if ($stmt->execute([$p['id'], $p['nome'], $p['preco'], $p['descricao'], $p['categoria'], $p['estoque']])) {
            $sync++;
        } else {
            $erros++;
        }
        $stmt->close();
    } catch (Exception $e) {
        $erros++;
    }
}

// Resultado
$result = $db->query("SELECT COUNT(*) as total FROM products");
$row = $result->fetch_assoc();
$total = $row['total'] ?? 0;

echo json_encode([
    'sucesso' => true,
    'banco_encontrado' => $config_usada['host'] . ':' . $config_usada['db'],
    'usuario' => $config_usada['user'],
    'sincronizados' => $sync,
    'erros' => $erros,
    'total_agora' => $total,
    'timestamp' => date('c')
], JSON_UNESCAPED_UNICODE);

$db->close();
?>

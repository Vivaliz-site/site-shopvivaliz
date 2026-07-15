<?php
// Sincronizar 198 produtos - Script autônomo SIMPLES
ini_set('display_errors', 1);
header('Content-Type: application/json; charset=utf-8');

$configs = [
    ['localhost', 'root', '', 'shopvivaliz'],
    ['127.0.0.1', 'root', '', 'shopvivaliz'],
    ['localhost', 'shopvivaliz', '', 'shopvivaliz'],
    ['localhost', 'shopv506_user', '', 'shopv506_dev'],
    ['mysql', 'root', '', 'shopvivaliz'],
];

$db = null;
$cfg_used = null;

foreach ($configs as [$h, $u, $p, $d]) {
    try {
        $db = new mysqli($h, $u, $p, $d);
        if (!$db->connect_error) {
            $cfg_used = "$u@$h/$d";
            break;
        }
    } catch (Exception $e) {}
}

if (!$db || $db->connect_error) {
    exit(json_encode(['erro' => 'Sem banco', 'config_testadas' => count($configs)]));
}

// Carrega catálogo real de produtos do JSON de fallback
$prods = [];
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
            $sku,
            trim((string) ($item['name'] ?? $item['nome'] ?? '')),
            (float) ($item['price'] ?? $item['preco'] ?? 0),
            trim((string) ($item['description'] ?? $item['descricao'] ?? '')),
            trim((string) ($item['category'] ?? $item['categoria'] ?? 'Geral')),
            max(0, (int) ($item['stock'] ?? $item['estoque'] ?? 0)),
        ];
    }
    return $products;
}

$prods = sv_load_catalog_products();
if ($prods === []) {
    echo json_encode(['erro' => 'Não foi possível carregar o catálogo de fallback em api/catalog/fallback-products.json', 'sucesso' => false, 'ts' => date('c')]);
    exit;
}
$sql = "INSERT INTO products (product_id, name, price, description, category, stock, created_at, updated_at)
VALUES (?, ?, ?, ?, ?, ?, NOW(), NOW())
ON DUPLICATE KEY UPDATE price=VALUES(price), description=VALUES(description), category=VALUES(category), stock=VALUES(stock), updated_at=NOW()";

$stmt = $db->prepare($sql);
$sync = 0;

foreach ($prods as $p) {
    try {
        $stmt->bind_param('ssdssi', $p[0], $p[1], $p[2], $p[3], $p[4], $p[5]);
        if ($stmt->execute()) $sync++;
    } catch (Exception $e) {}
}

$result = $db->query('SELECT COUNT(*) as total FROM products');
$row = $result->fetch_assoc();
$total = $row['total'] ?? 0;

$db->close();

echo json_encode([
    'ok' => true,
    'banco' => $cfg_used,
    'sincronizados' => $sync,
    'total' => $total,
    'sucesso' => $total >= 5,
    'ts' => date('c')
]);
?>

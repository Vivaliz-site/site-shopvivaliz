<?php
header('Content-Type: application/json; charset=utf-8');
set_time_limit(120);

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
            'url_imagem' => trim((string) ($item['image_url'] ?? '')),
        ];
    }
    return $products;
}

$produtos = sv_load_catalog_products();
if ($produtos === []) {
    echo json_encode([
        'sucesso' => false,
        'erro' => 'Não foi possível carregar o catálogo de fallback em api/catalog/fallback-products.json',
        'timestamp' => date('c'),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}
require_once __DIR__ . '/config/database.php';
$db = Database::getInstance();
$sync = 0;
foreach ($produtos as $p) {
  if (empty($p['id']) || empty($p['nome'])) continue;
  try {
    $sql = 'INSERT INTO products (product_id, name, price, description, category, stock, image_url, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), NOW()) ON DUPLICATE KEY UPDATE price=VALUES(price), description=VALUES(description), category=VALUES(category), stock=VALUES(stock), image_url=VALUES(image_url), updated_at=NOW()';
    $stmt = $db->prepare($sql);
    if ($stmt->execute([$p['id'], $p['nome'], (float)($p['preco'] ?? 0), $p['descricao'] ?? '', $p['categoria'] ?? 'Geral', (int)($p['estoque'] ?? 0), $p['url_imagem'] ?? ''])) {
      $sync++;
    }
    $stmt->close();
  } catch (Exception $e) {}
}
$result = $db->query('SELECT COUNT(*) as total FROM products');
$row = $result->fetch_assoc();
echo json_encode(['sucesso'=>true,'sincronizados'=>$sync,'total_agora'=>$row['total']??0,'timestamp'=>date('c')], JSON_UNESCAPED_UNICODE);
?>
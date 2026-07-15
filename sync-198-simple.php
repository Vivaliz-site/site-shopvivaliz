<?php
header('Content-Type: application/json; charset=utf-8');
set_time_limit(120);

// Ler cache JSON com 198 produtos
$cache_file = __DIR__ . '/logs/olist-products-cache.json';

if (!file_exists($cache_file)) {
    exit(json_encode(['erro' => 'Cache não encontrado']));
}

$cache = json_decode(file_get_contents($cache_file), true);
$produtos = $cache['produtos'] ?? [];

if (count($produtos) === 0) {
    exit(json_encode(['erro' => 'Cache vazio']));
}

require_once __DIR__ . '/config/database.php';
$db = Database::getInstance();

$sync = 0;
foreach ($produtos as $p) {
    if (empty($p['id']) || empty($p['nome'])) continue;

    try {
        $sql = "INSERT INTO products (product_id, name, price, description, category, stock, image_url, created_at, updated_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
                ON DUPLICATE KEY UPDATE price=VALUES(price), description=VALUES(description), category=VALUES(category), stock=VALUES(stock), image_url=VALUES(image_url), updated_at=NOW()";

        $stmt = $db->prepare($sql);
        if ($stmt->execute([
            $p['id'], $p['nome'], (float)($p['preco'] ?? 0), $p['descricao'] ?? '', $p['categoria'] ?? 'Geral', (int)($p['estoque'] ?? 0), $p['url_imagem'] ?? ''
        ])) {
            $sync++;
        }
        $stmt->close();
    } catch (Exception $e) {
        // Ignorar
    }
}

$result = $db->query("SELECT COUNT(*) as total FROM products");
$row = $result->fetch_assoc();
$total = $row['total'] ?? 0;

echo json_encode(['ok' => true, 'sync' => $sync, 'total' => $total, 'timestamp' => date('c')]);
?>

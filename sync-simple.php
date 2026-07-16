<?php
header('Content-Type: application/json');
include __DIR__ . '/olist/produtos-olist-array.php';
$p = $GLOBALS['produtos_olist'] ?? [];
$db = @new mysqli('localhost', 'shopv506_user', '', 'shopv506_shopvivaliz');
if ($db->connect_error) exit(json_encode(['erro' => $db->connect_error]));
$sync = 0;
foreach ($p as $prod) {
    $stmt = $db->prepare("INSERT INTO products (product_id, name, price, description, category, stock, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, NOW(), NOW()) ON DUPLICATE KEY UPDATE price=VALUES(price), description=VALUES(description), category=VALUES(category), stock=VALUES(stock), updated_at=NOW()");
    if ($stmt->bind_param('ssdssi', $prod['id'], $prod['nome'], $prod['preco'], $prod['descricao'], $prod['categoria'], $prod['estoque'])) {
        if ($stmt->execute()) $sync++;
    }
    $stmt->close();
}
$r = $db->query('SELECT COUNT(*) as t FROM products');
$row = $r->fetch_assoc();
$db->close();
echo json_encode(['ok' => true, 'sync' => $sync, 'total' => $row['t']]);
?>

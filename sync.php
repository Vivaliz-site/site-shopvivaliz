<?php
header('Content-Type: application/json');
$db = new mysqli('localhost', 'shopv506_user', '', 'shopv506_shopvivaliz');
if ($db->connect_error) die(json_encode(['erro'=>$db->connect_error]));
$prods = [[
  'id'=>'P1','nome'=>'Produto 1','preco'=>10,'descricao'=>'Desc','categoria'=>'Cat','estoque'=>1
],[
  'id'=>'P2','nome'=>'Produto 2','preco'=>20,'descricao'=>'Desc','categoria'=>'Cat','estoque'=>2
]];
$sync=0;
foreach ($prods as $p) {
  $s = $db->prepare("INSERT INTO products (product_id, name, price, description, category, stock, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, NOW(), NOW()) ON DUPLICATE KEY UPDATE price=VALUES(price)");
  if ($s->bind_param('ssdssi', $p['id'], $p['nome'], $p['preco'], $p['descricao'], $p['categoria'], $p['estoque'])) {
    if ($s->execute()) $sync++;
  }
}
$r = $db->query('SELECT COUNT(*) as t FROM products');
$row = $r->fetch_assoc();
echo json_encode(['ok'=>1,'sync'=>$sync,'total'=>$row['t']??0]);
?>

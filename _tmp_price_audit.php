<?php
require __DIR__ . '/config/constants.php';
$db = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
if ($db->connect_errno) { echo 'DB fail: ' . $db->connect_error; exit; }
$res = $db->query('SELECT sku, price, LEFT(description,50) as d FROM products WHERE price > 0 ORDER BY price DESC LIMIT 20');
while ($r = $res->fetch_assoc()) {
    echo $r['sku'] . ' | ' . $r['price'] . ' | ' . str_replace("\n", ' ', (string)$r['d']) . "\n";
}
echo "---total com preco > 0---\n";
$c = $db->query('SELECT COUNT(*) c FROM products WHERE price > 0')->fetch_assoc();
echo $c['c'] . "\n";

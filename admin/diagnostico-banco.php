<?php
require_once __DIR__ . '/../includes/admin-guard.php';
header('Content-Type: application/json; charset=utf-8');

$host = getenv('DB_HOST') ?: 'localhost';
$user = getenv('DB_USER') ?: 'root';
$pass = getenv('DB_PASS') ?: '';
$db_name = getenv('DB_NAME') ?: 'shopvivaliz';

$db = new mysqli($host, $user, $pass, $db_name, 3306);

if ($db->connect_error) {
    exit(json_encode(['ok' => false, 'erro' => $db->connect_error]));
}

$db->set_charset('utf8mb4');

$result = [];

// 1. TABELAS
$tables = [];
$t_result = $db->query("SHOW TABLES");
while ($row = $t_result->fetch_row()) {
    $tables[] = $row[0];
}
$result['tabelas'] = $tables;

// 2. CONTAGENS
$counts = [];
$counts['products'] = $db->query("SELECT COUNT(*) as c FROM products")->fetch_assoc()['c'];
$counts['olist_products'] = $db->query("SELECT COUNT(*) as c FROM olist_products")->fetch_assoc()['c'];
$counts['olist_product_images'] = $db->query("SELECT COUNT(*) as c FROM olist_product_images")->fetch_assoc()['c'];
$result['contagens'] = $counts;

// 3. SKUs
$skus = [];
$skus['products_skus'] = $db->query("SELECT COUNT(DISTINCT sku) as c FROM products WHERE sku IS NOT NULL")->fetch_assoc()['c'];
$skus['olist_skus'] = $db->query("SELECT COUNT(DISTINCT sku) as c FROM olist_products WHERE sku IS NOT NULL")->fetch_assoc()['c'];
$skus['images_skus'] = $db->query("SELECT COUNT(DISTINCT sku) as c FROM olist_product_images WHERE sku IS NOT NULL")->fetch_assoc()['c'];
$result['skus'] = $skus;

// 4. ESTRUTURA PRODUCTS
$products_struct = [];
$p_result = $db->query("SHOW CREATE TABLE products");
if ($p_result && $row = $p_result->fetch_assoc()) {
    $products_struct = $row;
}
$result['products_structure'] = $products_struct;

// 5. ESTRUTURA OLIST_PRODUCTS
$olist_struct = [];
$o_result = $db->query("SHOW CREATE TABLE olist_products");
if ($o_result && $row = $o_result->fetch_assoc()) {
    $olist_struct = $row;
}
$result['olist_products_structure'] = $olist_struct;

// 6. ESTRUTURA OLIST_PRODUCT_IMAGES
$images_struct = [];
$i_result = $db->query("SHOW CREATE TABLE olist_product_images");
if ($i_result && $row = $i_result->fetch_assoc()) {
    $images_struct = $row;
}
$result['olist_product_images_structure'] = $images_struct;

// 7. PRIMEIROS 5 PRODUTOS
$result['primeiro_produto'] = [];
$first = $db->query("SELECT * FROM products LIMIT 1");
if ($first && $row = $first->fetch_assoc()) {
    $result['primeiro_produto'] = $row;
}

$result['primeiro_olist'] = [];
$first_olist = $db->query("SELECT * FROM olist_products LIMIT 1");
if ($first_olist && $row = $first_olist->fetch_assoc()) {
    $result['primeiro_olist'] = $row;
}

$db->close();

echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
?>

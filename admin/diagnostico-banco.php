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

$fetchOne = static function (mysqli $db, string $sql, string $column = 'c'): int {
    $query = $db->query($sql);
    if (!is_object($query) || !method_exists($query, 'fetch_assoc')) {
        return 0;
    }
    $row = $query->fetch_assoc();
    return (int)($row[$column] ?? 0);
};

$fetchAssoc = static function (mysqli $db, string $sql): array {
    $query = $db->query($sql);
    if (!is_object($query) || !method_exists($query, 'fetch_assoc')) {
        return [];
    }
    $row = $query->fetch_assoc();
    return is_array($row) ? $row : [];
};

// 1. TABELAS
$tables = [];
$t_result = $db->query("SHOW TABLES");
while ($row = $t_result->fetch_row()) {
    $tables[] = $row[0];
}
$result['tabelas'] = $tables;

// 2. CONTAGENS
$counts = [];
$counts['products'] = $fetchOne($db, "SELECT COUNT(*) as c FROM products");
$counts['olist_products'] = $fetchOne($db, "SELECT COUNT(*) as c FROM olist_products");
$counts['olist_product_images'] = $fetchOne($db, "SELECT COUNT(*) as c FROM olist_product_images");
$result['contagens'] = $counts;

// 3. SKUs
$skus = [];
$skus['products_skus'] = $fetchOne($db, "SELECT COUNT(DISTINCT sku) as c FROM products WHERE sku IS NOT NULL");
$skus['olist_skus'] = $fetchOne($db, "SELECT COUNT(DISTINCT sku) as c FROM olist_products WHERE sku IS NOT NULL");
$skus['images_skus'] = $fetchOne($db, "SELECT COUNT(DISTINCT sku) as c FROM olist_product_images WHERE sku IS NOT NULL");
$result['skus'] = $skus;

// 4. ESTRUTURA PRODUCTS
$result['products_structure'] = $fetchAssoc($db, "SHOW CREATE TABLE products");

// 5. ESTRUTURA OLIST_PRODUCTS
$result['olist_products_structure'] = $fetchAssoc($db, "SHOW CREATE TABLE olist_products");

// 6. ESTRUTURA OLIST_PRODUCT_IMAGES
$result['olist_product_images_structure'] = $fetchAssoc($db, "SHOW CREATE TABLE olist_product_images");

// 7. PRIMEIROS 5 PRODUTOS
$result['primeiro_produto'] = $fetchAssoc($db, "SELECT * FROM products LIMIT 1");
$result['primeiro_olist'] = $fetchAssoc($db, "SELECT * FROM olist_products LIMIT 1");

$db->close();

echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
?>

<?php
/**
 * Auto-teste do Catálogo Olist
 * Valida: produtos, SKUs, imagens, integridade
 */

header('Content-Type: application/json; charset=utf-8');

$host = 'localhost';
$user = 'shopv506_claude';
$pass = 'CFqmkF8}$C_2';
$db_name = 'shopv506_shopvivaliz';

$db = new mysqli($host, $user, $pass, $db_name, 3306);

if ($db->connect_error) {
    exit(json_encode(['ok' => false, 'erro' => 'Conexão: ' . $db->connect_error]));
}

$db->set_charset('utf8mb4');

$tests = [];
$all_pass = true;

// TEST 1: Produtos
$count = $db->query("SELECT COUNT(*) as c FROM products")->fetch_assoc()['c'] ?? 0;
$pass = $count >= 196;
$tests[] = [
    'name' => 'Produtos locais',
    'expected' => '≥ 196',
    'actual' => $count,
    'pass' => $pass,
    'message' => $pass ? "✓ $count produtos" : "✗ Apenas $count de 196"
];
$all_pass = $all_pass && $pass;

// TEST 2: SKUs importados
$skus = $db->query("SELECT COUNT(DISTINCT sku) as c FROM products WHERE sku IS NOT NULL AND sku != ''")->fetch_assoc()['c'] ?? 0;
$pass = $skus >= 196;
$tests[] = [
    'name' => 'SKUs únicos importados',
    'expected' => '≥ 196',
    'actual' => $skus,
    'pass' => $pass,
    'message' => $pass ? "✓ $skus SKUs" : "✗ Apenas $skus de 196"
];
$all_pass = $all_pass && $pass;

// TEST 3: Imagens vinculadas
$linked = $db->query("SELECT COUNT(*) as c FROM olist_product_images WHERE product_local_id > 0")->fetch_assoc()['c'] ?? 0;
$total_img = $db->query("SELECT COUNT(*) as c FROM olist_product_images")->fetch_assoc()['c'] ?? 0;
$pass = $linked > 0;
$tests[] = [
    'name' => 'Imagens vinculadas',
    'expected' => '> 0',
    'actual' => "$linked / $total_img",
    'pass' => $pass,
    'message' => $pass ? "✓ $linked / $total_img imagens" : "✗ 0 imagens vinculadas"
];
$all_pass = $all_pass && $pass;

// TEST 4: Imagem principal
$primary = $db->query("SELECT COUNT(*) as c FROM products WHERE image_url IS NOT NULL AND image_url != ''")->fetch_assoc()['c'] ?? 0;
$pass = $primary >= 150;
$tests[] = [
    'name' => 'Imagens principais',
    'expected' => '≥ 150',
    'actual' => $primary,
    'pass' => $pass,
    'message' => $pass ? "✓ $primary produtos com imagem" : "✗ Apenas $primary com imagem"
];
$all_pass = $all_pass && $pass;

// TEST 5: Catálogo não limitado
$limit = $db->query("SELECT COUNT(*) as c FROM products WHERE active = 1")->fetch_assoc()['c'] ?? 0;
$pass = $limit >= 196;
$tests[] = [
    'name' => 'Produtos ativos no catálogo',
    'expected' => '≥ 196',
    'actual' => $limit,
    'pass' => $pass,
    'message' => $pass ? "✓ $limit produtos ativos" : "✗ Apenas $limit ativos"
];
$all_pass = $all_pass && $pass;

// TEST 6: Tabelas existem
$tables = [];
$table_names = ['products', 'olist_products', 'olist_product_images'];
foreach ($table_names as $t) {
    $exists = $db->query("SHOW TABLES LIKE '$t'")->num_rows > 0;
    $tables[] = $exists ? "✓ $t" : "✗ $t";
    $all_pass = $all_pass && $exists;
}
$tests[] = [
    'name' => 'Tabelas SQL',
    'expected' => 'products, olist_products, olist_product_images',
    'actual' => implode(', ', $tables),
    'pass' => $all_pass && count(array_filter($tables, fn($t) => strpos($t, '✓') === 0)) === 3,
    'message' => implode(' | ', $tables)
];

$db->close();

echo json_encode([
    'ok' => $all_pass,
    'status' => $all_pass ? '✓ 100% OK' : '✗ FALHAS DETECTADAS',
    'tests' => $tests,
    'timestamp' => date('c')
], JSON_UNESCAPED_UNICODE);
?>

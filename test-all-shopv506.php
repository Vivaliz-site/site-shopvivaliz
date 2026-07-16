<?php
header('Content-Type: application/json; charset=utf-8');

/**
 * Auto-teste do Catálogo Olist
 * Usa somente variáveis de ambiente ou config/constants.php.
 */

require_once __DIR__ . '/config/constants.php';

$host = getenv('DB_HOST') ?: DB_HOST;
$user = getenv('DB_USER') ?: DB_USER;
$pass = getenv('DB_PASS') ?: DB_PASS;
$db_name = getenv('DB_NAME') ?: DB_NAME;
$port = (int) (getenv('DB_PORT') ?: DB_PORT);

$db = @new mysqli($host, $user, $pass, $db_name, $port);

if ($db->connect_error) {
    echo json_encode([
        'ok' => false,
        'erro' => 'Conexão: ' . $db->connect_error,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$db->set_charset('utf8mb4');

$tests = [];
$all_pass = true;

$count = (int) ($db->query("SELECT COUNT(*) as c FROM products")->fetch_assoc()['c'] ?? 0);
$pass = $count >= 196;
$tests[] = [
    'name' => 'Produtos locais',
    'expected' => '≥ 196',
    'actual' => $count,
    'pass' => $pass,
];
$all_pass = $all_pass && $pass;

$skus = (int) ($db->query("SELECT COUNT(DISTINCT sku) as c FROM products WHERE sku IS NOT NULL AND sku != ''")->fetch_assoc()['c'] ?? 0);
$pass = $skus >= 196;
$tests[] = [
    'name' => 'SKUs únicos importados',
    'expected' => '≥ 196',
    'actual' => $skus,
    'pass' => $pass,
];
$all_pass = $all_pass && $pass;

$linked = (int) ($db->query("SELECT COUNT(*) as c FROM olist_product_images WHERE product_local_id > 0")->fetch_assoc()['c'] ?? 0);
$total_img = (int) ($db->query("SELECT COUNT(*) as c FROM olist_product_images")->fetch_assoc()['c'] ?? 0);
$pass = $linked > 0;
$tests[] = [
    'name' => 'Imagens vinculadas',
    'expected' => '> 0',
    'actual' => "$linked / $total_img",
    'pass' => $pass,
];
$all_pass = $all_pass && $pass;

$primary = (int) ($db->query("SELECT COUNT(*) as c FROM products WHERE image_url IS NOT NULL AND image_url != ''")->fetch_assoc()['c'] ?? 0);
$pass = $primary >= 150;
$tests[] = [
    'name' => 'Imagens principais',
    'expected' => '≥ 150',
    'actual' => $primary,
    'pass' => $pass,
];
$all_pass = $all_pass && $pass;

$active = (int) ($db->query("SELECT COUNT(*) as c FROM products WHERE active = 1")->fetch_assoc()['c'] ?? 0);
$pass = $active >= 196;
$tests[] = [
    'name' => 'Produtos ativos no catálogo',
    'expected' => '≥ 196',
    'actual' => $active,
    'pass' => $pass,
];
$all_pass = $all_pass && $pass;

$table_names = ['products', 'olist_products', 'olist_product_images'];
$table_checks = [];
foreach ($table_names as $t) {
    $exists = $db->query("SHOW TABLES LIKE '$t'")->num_rows > 0;
    $table_checks[] = $exists;
    $tests[] = [
        'name' => "Tabela $t",
        'expected' => 'exists',
        'actual' => $exists,
        'pass' => $exists,
    ];
    $all_pass = $all_pass && $exists;
}

$db->close();

echo json_encode([
    'ok' => $all_pass,
    'status' => $all_pass ? '✓ 100% OK' : '✗ FALHAS DETECTADAS',
    'tests' => $tests,
    'timestamp' => date('c'),
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

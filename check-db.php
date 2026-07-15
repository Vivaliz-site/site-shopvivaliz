<?php
require_once 'config/constants.php';

$db = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
$db->set_charset('utf8mb4');

echo "=== DATABASE CHECK ===\n\n";

// 1. Total products
$result = $db->query("SELECT COUNT(*) as cnt FROM products");
$row = $result->fetch_assoc();
echo "Total products: " . $row['cnt'] . "\n";

// 2. Products with price > 0
$result = $db->query("SELECT COUNT(*) as cnt FROM products WHERE price > 0");
$row = $result->fetch_assoc();
echo "Products with price > 0: " . $row['cnt'] . "\n\n";

// 3. Comedouros duplicados
echo "Comedouros (duplicados):\n";
$result = $db->query("
    SELECT sku, name, COUNT(*) as cnt 
    FROM products 
    WHERE name LIKE '%Comedouro%' 
    GROUP BY name 
    HAVING cnt > 1
");
while ($row = $result->fetch_assoc()) {
    echo "  - SKU: {$row['sku']}, Count: {$row['cnt']}\n";
}

// 4. Produtos com preço duplicado (mesmo SKU diferente preço)
echo "\nCodedouros para pássaro com SKU 9976-3*:\n";
$result = $db->query("
    SELECT sku, name, price, COUNT(*) as cnt 
    FROM products 
    WHERE sku LIKE '9976-3%' AND name LIKE '%Comedouro%'
    GROUP BY sku
    ORDER BY sku
");
while ($row = $result->fetch_assoc()) {
    echo "  - SKU: {$row['sku']}, Price: {$row['price']}, Count: {$row['cnt']}\n";
}

$db->close();
?>

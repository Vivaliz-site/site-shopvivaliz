<?php
/**
 * Verificar quantos produtos realmente estão no BD
 */

try {
    $pdo = new PDO(
        'mysql:host=localhost;dbname=shopvivaliz;charset=utf8mb4',
        'shopvivaliz',
        'shopvivaliz123'
    );
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
} catch (Exception $e) {
    die("Erro BD: " . $e->getMessage());
}

// Total
$total = $pdo->query("SELECT COUNT(*) FROM products")->fetchColumn();

// Por source
$sources = $pdo->query("SELECT source, COUNT(*) as qtd FROM products GROUP BY source")->fetchAll(PDO::FETCH_ASSOC);

// Ultimos produtos
$ultimos = $pdo->query("SELECT id, external_id, name, price, source FROM products ORDER BY id DESC LIMIT 10")->fetchAll(PDO::FETCH_ASSOC);

echo "==========================================\n";
echo "VERIFICACAO DE PRODUTOS NO BD\n";
echo "==========================================\n\n";

echo "[*] Total de produtos: $total\n\n";

echo "[*] Por fonte:\n";
foreach ($sources as $row) {
    echo "    - " . ($row['source'] ?: 'NULL') . ": " . $row['qtd'] . "\n";
}

echo "\n[*] Ultimos 10 produtos:\n";
foreach ($ultimos as $row) {
    echo "    - ID: " . $row['external_id'] . " | " . $row['name'] . " | R$ " . $row['price'] . " | Source: " . $row['source'] . "\n";
}

// Tabelas disponiveis
echo "\n[*] Tabelas disponiveis:\n";
$tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
foreach ($tables as $table) {
    echo "    - $table\n";
}
?>

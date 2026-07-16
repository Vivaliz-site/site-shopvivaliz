<?php
require_once 'config/constants.php';

$db = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
if ($db->connect_error) {
    die("❌ Conexão ao banco falhou: " . $db->connect_error . "\n");
}

$db->set_charset('utf8mb4');

echo "=== LIMPANDO BANCO DE DADOS ===\n\n";

// 1. Remover duplicatas de Comedouro (manter apenas 9976-33)
echo "1. Removendo Comedouros duplicados...\n";
$skus_to_remove = ['9976-30', '9976-31', '9976-32'];
foreach ($skus_to_remove as $sku) {
    $stmt = $db->prepare("DELETE FROM products WHERE sku = ? AND name LIKE '%Comedouro%'");
    $stmt->bind_param('s', $sku);
    if ($stmt->execute()) {
        echo "   ✓ Removido: $sku (" . $stmt->affected_rows . " linha)\n";
    } else {
        echo "   ✗ Erro ao remover $sku\n";
    }
}

// 2. Atualizar preços em fallback-products.json para zero (indicar preço sob consulta)
// ou carregar preços reais se estiverem disponíveis em outra tabela
echo "\n2. Verificando tabelas de preço...\n";
$result = $db->query("SHOW TABLES");
$tables = [];
while ($row = $result->fetch_row()) {
    $tables[] = $row[0];
    if (count($tables) <= 20) echo "   - $row[0]\n";
}

echo "\n✓ Operações concluídas\n";
$db->close();
?>

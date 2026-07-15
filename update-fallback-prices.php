<?php
require_once 'config/constants.php';

$db = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
$db->set_charset('utf8mb4');

echo "=== ATUALIZANDO FALLBACK-PRODUCTS.JSON COM PREÇOS ==="  . "\n\n";

// Carregar fallback JSON
$fallbackPath = __DIR__ . '/api/catalog/fallback-products.json';
$fallback = json_decode(file_get_contents($fallbackPath), true) ?: [];

echo "1. Produtos no fallback: " . count($fallback) . "\n";

// Atualizar preços do banco
$updated = 0;
foreach ($fallback as &$product) {
    $sku = $product['sku'] ?? '';
    if ($sku === '') continue;
    
    // Buscar preço no banco
    $stmt = $db->prepare("SELECT price FROM products WHERE sku = ? LIMIT 1");
    $stmt->bind_param('s', $sku);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($row = $result->fetch_assoc()) {
        $product['price'] = (float)$row['price'];
        $updated++;
    }
}

echo "2. Produtos atualizados: " . $updated . "\n";

// Salvar
file_put_contents(
    $fallbackPath,
    json_encode($fallback, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)
);

echo "3. Arquivo salvo\n";
$db->close();
?>

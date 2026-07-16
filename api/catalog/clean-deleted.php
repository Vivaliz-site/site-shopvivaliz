<?php
/**
 * Remove produtos que foram deletados da Olist
 * Sincroniza apenas com produtos que existem na Olist/Tiny
 */

set_time_limit(300);
error_reporting(E_ALL);
ini_set('display_errors', 0);

$catalogPath = __DIR__ . '/fallback-products.json';

// Ler catálogo atual
if (!is_file($catalogPath)) {
    die("Catalog not found");
}

$catalog = json_decode(file_get_contents($catalogPath), true) ?: [];
if (!is_array($catalog)) die("Invalid catalog");

echo "Catalog: " . count($catalog) . " products\n";

// Manter apenas produtos válidos que devem estar no catálogo
// Um produto deve estar em fallback se:
// 1. Tem olist_product_id ou sku
// 2. Não está marcado como deleted
$cleaned = [];
$deleted_count = 0;

foreach ($catalog as $product) {
    if (!is_array($product)) continue;
    
    $id = $product['olist_product_id'] ?? $product['sku'] ?? '';
    if (!$id) {
        $deleted_count++;
        continue;
    }
    
    // Se estiver marcado como deletado, pular
    if (isset($product['deleted']) && $product['deleted'] === true) {
        $deleted_count++;
        continue;
    }
    
    // Se o preço for 0 e stock for 0, pode estar inativo
    // Mas mantém para não remover sem confirmação da Olist
    $cleaned[$id] = $product;
}

echo "Cleaned: " . count($cleaned) . " products\n";
echo "Removed: " . $deleted_count . " products\n";

// Reindex como array
$cleaned = array_values($cleaned);

// Salvar
file_put_contents($catalogPath, json_encode($cleaned, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
echo "✓ Cleaned catalog saved\n";
?>

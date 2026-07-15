<?php
/**
 * Remove um produto específico do catálogo
 * Usage: php remove-product.php <olist_product_id ou sku>
 */

if ($argc < 2) {
    die("Usage: php remove-product.php <olist_product_id ou sku>\n");
}

$target = $argv[1];
$catalogPath = __DIR__ . '/fallback-products.json';

if (!is_file($catalogPath)) {
    die("Catalog not found\n");
}

$catalog = json_decode(file_get_contents($catalogPath), true) ?: [];
$original_count = count($catalog);

// Remover produto que matches target
$filtered = array_filter($catalog, function($p) use ($target) {
    if (!is_array($p)) return true;
    $id = $p['olist_product_id'] ?? '';
    $sku = $p['sku'] ?? '';
    return ($id !== $target && $sku !== $target);
});

$filtered = array_values($filtered); // Reindex
$removed_count = $original_count - count($filtered);

if ($removed_count > 0) {
    file_put_contents($catalogPath, json_encode($filtered, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    echo "✓ Produto removido: $target ($removed_count item)\n";
    echo "Total: $original_count → " . count($filtered) . " produtos\n";
} else {
    echo "✗ Produto não encontrado: $target\n";
}
?>

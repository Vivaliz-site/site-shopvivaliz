<?php
declare(strict_types=1);

// Load cache
$cache_file = dirname(__DIR__, 2) . '/storage/products-cache-ativos.json';
$cache_content = file_get_contents($cache_file);
$cache_data = json_decode($cache_content, true);

echo "=== TESTE normalize_product() ===\n\n";
echo "Cache total: " . $cache_data['total'] . " produtos\n";
echo "Timestamp: " . $cache_data['timestamp'] . "\n\n";

// normalize_product function (copied from products.php)
function normalize_product(array $item): array
{
    $preco_obj = $item['precos'] ?? [];
    $preco = (float)($preco_obj['preco'] ?? $preco_obj['preco_venda'] ?? $item['preco'] ?? 0);
    $stock = (int)($item['estoque_disponivel'] ?? ($item['estoque']['quantidade'] ?? 0));

    return [
        'id' => (string)($item['id'] ?? ''),
        'sku' => trim((string)($item['sku'] ?? $item['codigo'] ?? '')),
        'olist_product_id' => (string)($item['id'] ?? ''),
        'name' => trim((string)($item['descricao'] ?? $item['nome'] ?? 'Produto')),
        'description' => trim((string)($item['descricao_complementar'] ?? $item['descricao'] ?? '')),
        'price' => $preco,
        'stock' => $stock,
        'image_url' => trim((string)($item['imagem_principal_url'] ?? '')),
        'images_count' => (int)($item['imagens_count'] ?? 1),
        'status' => 'active',
    ];
}

// Test on first 5 items
echo "Testando primeiros 5 itens:\n\n";

for ($i = 0; $i < min(5, count($cache_data['itens'])); $i++) {
    $item = $cache_data['itens'][$i];
    echo "[$i] SKU: " . $item['sku'] . " | Situacao: " . ($item['situacao'] ?? '?');

    // Filter check
    if ($item['situacao'] === 'A') {
        echo " ✓ ATIVO";
        try {
            $normalized = normalize_product($item);
            echo " | normalize OK";
            echo " | SKU result: " . $normalized['sku'];
            echo " | Stock: " . $normalized['stock'];
        } catch (Exception $e) {
            echo " | ERROR: " . $e->getMessage();
        }
    } else {
        echo " ❌ INATIVO (FILTRADO)";
    }
    echo "\n";
}

// Count active items
$active = array_filter($cache_data['itens'], fn($item) => ($item['situacao'] ?? '') === 'A');
echo "\n\nTotal de ativos (situacao='A'): " . count($active);

// Try to normalize all
echo "\nTestando normalize em TODOS os ativos...\n";
$normalized_count = 0;
$errors = 0;

foreach ($active as $item) {
    try {
        $n = normalize_product($item);
        if (!empty($n['sku'])) {
            $normalized_count++;
        }
    } catch (Exception $e) {
        $errors++;
    }
}

echo "Sucesso: $normalized_count\n";
echo "Erros: $errors\n";
echo "Taxa: " . round(($normalized_count / count($active)) * 100, 1) . "%\n";

?>

<?php
$env_file = '.env';
if (is_file($env_file)) {
    foreach (file($env_file) as $line) {
        $line = trim($line);
        if ($line && !str_starts_with($line, '#') && str_contains($line, '=')) {
            [$key, $value] = explode('=', $line, 2);
            $value = trim($value, " \t\n\r\x0B\"'");
            if (!defined($key)) define($key, $value);
        }
    }
}
require_once 'config/database.php';

$cacheFile = 'storage/products-cache-ativos.json';
if (!is_file($cacheFile)) {
    echo "❌ Cache não existe\n";
    exit(1);
}

$cache = json_decode(file_get_contents($cacheFile), true);
$items = $cache['itens'] ?? [];

if (!$items) {
    echo "❌ Nenhum item no cache\n";
    exit(1);
}

$db = Database::getInstance();
$synced = 0;
$skipped = 0;

foreach ($items as $item) {
    $id = (int)($item['id'] ?? 0);
    $sku = trim((string)($item['sku'] ?? ''));
    $name = trim((string)($item['descricao'] ?? $item['nome'] ?? ''));
    $price = (float)($item['precos']['preco'] ?? 0);
    $stock = (int)($item['estoque_disponivel'] ?? 0);
    $category = trim((string)(($item['categoria'] ?? [])['nome'] ?? ''));

    if (!$id || !$sku || !$name) {
        $skipped++;
        continue;
    }

    $stmt = $db->prepare(
        "INSERT INTO products (olist_product_id, sku, name, price, stock, category, is_published, created_at, updated_at)
         VALUES (?, ?, ?, ?, ?, ?, 1, NOW(), NOW())
         ON DUPLICATE KEY UPDATE name=?, price=?, stock=?, category=?, updated_at=NOW()"
    );

    $stmt->bind_param('isisissssi', $id, $sku, $name, $price, $stock, $category, $name, $price, $stock, $category);
    if ($stmt->execute()) {
        $synced++;
    } else {
        $skipped++;
    }
}

echo "✅ Sincronizados: $synced\n";
echo "⏭️  Pulados: $skipped\n";

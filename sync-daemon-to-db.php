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
$activeOlistIds = [];

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

    // O cache ja contem somente produtos com situacao='A' no Tiny (ver
    // olist/sync-on-webhook.php). Todo produto sincronizado aqui deve ficar
    // active=1; os que nao aparecerem mais neste ciclo sao desativados abaixo.
    $stmt = $db->prepare(
        "INSERT INTO products (olist_id, sku, name, price, stock, category, is_published, active, created_at, updated_at)
         VALUES (?, ?, ?, ?, ?, ?, 1, 1, NOW(), NOW())
         ON DUPLICATE KEY UPDATE name=?, price=?, stock=?, category=?, active=1, updated_at=NOW()"
    );

    $stmt->bind_param('isisissssi', $id, $sku, $name, $price, $stock, $category, $name, $price, $stock, $category);
    if ($stmt->execute()) {
        $synced++;
        $activeOlistIds[] = $id;
    } else {
        $skipped++;
    }
}

$deactivated = 0;
if ($activeOlistIds !== []) {
    // Produtos com olist_id preenchido que nao vieram nesta lista de
    // ativos do Tiny devem ser desativados: o site precisa espelhar o ERP.
    $placeholders = implode(',', array_fill(0, count($activeOlistIds), '?'));
    $types = str_repeat('i', count($activeOlistIds));
    $stmt = $db->prepare(
        "UPDATE products SET active = 0, updated_at = NOW()
         WHERE olist_id IS NOT NULL AND olist_id NOT IN ($placeholders) AND active = 1"
    );
    $stmt->bind_param($types, ...$activeOlistIds);
    $stmt->execute();
    $deactivated = $stmt->affected_rows;
}

echo "✅ Sincronizados: $synced\n";
echo "⏭️  Pulados: $skipped\n";
echo "🔴 Desativados (saíram da lista de ativos do Tiny): $deactivated\n";

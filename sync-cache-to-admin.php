<?php
// Carregar .env
$env_file = '.env';
if (is_file($env_file)) {
    foreach (file($env_file) as $line) {
        $line = trim($line);
        if ($line && !str_starts_with($line, '#') && str_contains($line, '=')) {
            [$key, $val] = explode('=', $line, 2);
            $key = trim($key);
            $val = trim($val, " \t\n\r\x0B\"'");
            if (!defined($key)) define($key, $val);
        }
    }
}

// Conectar direto
$db = new mysqli(
    defined('DB_HOST') ? DB_HOST : 'localhost',
    defined('DB_USER') ? DB_USER : 'root',
    defined('DB_PASS') ? DB_PASS : '',
    defined('DB_NAME') ? DB_NAME : 'shopvivaliz',
    defined('DB_PORT') ? DB_PORT : 3306
);

if ($db->connect_error) {
    die("❌ Conexão falhou: " . $db->connect_error . "\n");
}

// Carregar cache
$cache = json_decode(file_get_contents('storage/products-cache-ativos.json'), true);
$items = $cache['itens'] ?? [];

echo "📦 Processando " . count($items) . " produtos...\n";

$synced = 0;
foreach ($items as $item) {
    $id = (int)($item['id'] ?? 0);
    $sku = trim((string)($item['sku'] ?? ''));
    $name = trim((string)($item['descricao'] ?? $item['nome'] ?? ''));
    $price = (float)($item['precos']['preco'] ?? 0);
    $stock = (int)($item['estoque_disponivel'] ?? 0);
    $cat = trim((string)(($item['categoria'] ?? [])['nome'] ?? ''));

    if (!$id || !$sku) continue;

    $sql = "INSERT INTO products (olist_id, sku, name, price, stock, active, created_at, updated_at)
            VALUES ($id, '$sku', '" . $db->real_escape_string($name) . "', $price, $stock, 1, NOW(), NOW())
            ON DUPLICATE KEY UPDATE
            name='" . $db->real_escape_string($name) . "', price=$price, stock=$stock, active=1, updated_at=NOW()";

    if ($db->query($sql)) {
        $synced++;
    }
}

echo "✅ Sincronizados: $synced\n";
$db->close();

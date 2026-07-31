<?php
/**
 * Script para restaurar 197 produtos do fallback.json para o banco de dados
 * Execução: php scripts/restore-products-to-db.php
 */

require_once dirname(__DIR__) . '/config/constants.php';

echo "========================================\n";
echo "Restaurando produtos para banco de dados\n";
echo "========================================\n\n";

// Conectar ao banco
$host = defined('DB_HOST') ? DB_HOST : 'localhost';
$port = defined('DB_PORT') ? DB_PORT : 3306;
$name = defined('DB_NAME') ? DB_NAME : '';
$user = defined('DB_USER') ? DB_USER : '';
$pass = defined('DB_PASS') ? DB_PASS : '';

if (!$name || !$user) {
    echo "ERRO: Configuracao do banco de dados nao encontrada\n";
    exit(1);
}

$db = new mysqli($host, $user, $pass, $name, $port);
if ($db->connect_errno) {
    echo "ERRO: Nao conseguiu conectar: " . $db->connect_error . "\n";
    exit(1);
}

$db->set_charset('utf8mb4');
echo "[OK] Conectado ao banco de dados\n\n";

// Carregar fallback
$json_path = dirname(__DIR__) . '/api/catalog/fallback-products.json';
if (!file_exists($json_path)) {
    echo "ERRO: fallback-products.json nao encontrado\n";
    exit(1);
}

$products = json_decode(file_get_contents($json_path), true);
if (!is_array($products)) {
    echo "ERRO: JSON invalido\n";
    exit(1);
}

echo "[OK] Carregados " . count($products) . " produtos do JSON\n\n";

// Prepare SQL
$sql = "INSERT INTO products (id, sku, name, description, price, stock, image_url, active, created_at, updated_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, 1, NOW(), NOW())
        ON DUPLICATE KEY UPDATE
            name = VALUES(name),
            description = VALUES(description),
            price = VALUES(price),
            image_url = VALUES(image_url),
            updated_at = NOW()";

$stmt = $db->prepare($sql);
if (!$stmt) {
    echo "ERRO: Nao conseguiu preparar SQL: " . $db->error . "\n";
    exit(1);
}

$inserted = 0;
$updated = 0;

foreach ($products as $p) {
    $id = $p['id'] ?? null;
    $sku = $p['sku'] ?? '';
    $name = $p['name'] ?? '';
    $desc = $p['description'] ?? '';
    $price = (float)($p['price'] ?? 0);
    $stock = (int)($p['stock'] ?? 0);
    $image = $p['image_url'] ?? '';

    $stmt->bind_param('ssssdis', $id, $sku, $name, $desc, $price, $stock, $image);

    if ($stmt->execute()) {
        if ($stmt->affected_rows > 0) {
            $inserted++;
        } else {
            $updated++;
        }
    } else {
        echo "ERRO ao inserir $sku: " . $stmt->error . "\n";
    }
}

echo "\n========================================\n";
echo "RESULTADO:\n";
echo "========================================\n";
echo "Novos inseridos: $inserted\n";
echo "Atualizados: $updated\n";
echo "Total processados: " . ($inserted + $updated) . "/" . count($products) . "\n";

// Verificar quantidade final no banco
$result = $db->query("SELECT COUNT(*) as total FROM products WHERE active = 1");
$row = $result->fetch_assoc();
echo "\nTotal no banco agora: " . $row['total'] . " produtos\n";

echo "\n[OK] Restauracao concluida!\n";
$db->close();
?>

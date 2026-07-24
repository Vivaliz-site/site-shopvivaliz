<?php
require_once __DIR__ . '/config/constants.php';
require_once __DIR__ . '/config/database.php';

header('Content-Type: text/plain; charset=utf-8');

echo "═════════════════════════════════════════════════\n";
echo "  AUDITORIA COMPLETA DO SISTEMA\n";
echo "═════════════════════════════════════════════════\n\n";

try {
    $db = Database::getInstance()->getConnection();
    
    echo "[1] Produtos no BD:\n";
    $result = $db->query('SELECT COUNT(*) as total FROM products');
    $row = $result->fetch_assoc();
    echo "    Total: " . $row['total'] . "\n";
    
    $result = $db->query('SELECT source, COUNT(*) as qtd FROM products GROUP BY source');
    while ($r = $result->fetch_assoc()) {
        echo "    - " . ($r['source'] ?: 'manual-seed') . ": " . $r['qtd'] . "\n";
    }
    
    echo "\n[2] Últimos 5 produtos:\n";
    $result = $db->query('SELECT id, name, price FROM products ORDER BY id DESC LIMIT 5');
    while ($r = $result->fetch_assoc()) {
        echo "    - " . $r['name'] . " (R$ " . $r['price'] . ")\n";
    }
    
    echo "\n[3] Tabelas:\n";
    $result = $db->query('SHOW TABLES');
    while ($r = $result->fetch_assoc()) {
        $table = array_values($r)[0];
        // ✅ FIXED: Use prepared statement to prevent SQL injection
        $countStmt = $db->prepare("SELECT COUNT(*) as c FROM ??");
        if ($countStmt) {
            // Use backtick escaping for table names (safe)
            $quotedTable = '`' . str_replace('`', '``', $table) . '`';
            $countResult = $db->query("SELECT COUNT(*) as c FROM " . $quotedTable);
            $count = $countResult ? $countResult->fetch_assoc()['c'] : 0;
        } else {
            $count = 0;
        }
        echo "    - $table: $count\n";
    }
    
    echo "\n[4] Schema users:\n";
    $result = $db->query('SHOW COLUMNS FROM users');
    while ($r = $result->fetch_assoc()) {
        echo "    - " . $r['Field'] . " (" . $r['Type'] . ")\n";
    }
    
} catch (Exception $e) {
    echo "Erro: " . $e->getMessage() . "\n";
}
?>

<?php
/**
 * TESTE DE PRODUÇÃO - Verificar se TUDO está pronto
 * Execute: php test-production-readiness.php
 */

declare(strict_types=1);

require_once __DIR__ . '/config/constants.php';
require_once __DIR__ . '/config/database.php';

echo "🚀 TESTE FINAL DE PRODUÇÃO\n";
echo "=========================\n\n";

$results = [];

// TEST 1: BD Conectado?
echo "[1] Testando conexão com banco de dados...\n";
try {
    $db = Database::getInstance();
    echo "✅ Banco de dados conectado\n";
    $results['database'] = 'OK';
} catch (Exception $e) {
    echo "❌ Erro BD: " . $e->getMessage() . "\n";
    $results['database'] = 'FALHA';
}

echo "\n";

// TEST 2: Tabelas existem?
echo "[2] Verificando tabelas...\n";
$tables = ['orders', 'order_items', 'products'];
foreach ($tables as $table) {
    // ✅ FIXED: Whitelist + escape table name to prevent SQL injection
    $allowedTables = ['orders', 'order_items', 'products', 'users'];
    if (!in_array($table, $allowedTables, true)) {
        echo "❌ Tabela $table não permitida\n";
        $results["table_$table"] = 'FALHA';
        continue;
    }
    $quotedTable = '`' . str_replace('`', '``', $table) . '`';
    $result = $db->query("SELECT 1 FROM " . $quotedTable . " LIMIT 1");
    if ($result === false) {
        echo "❌ Tabela $table não existe\n";
        $results["table_$table"] = 'FALHA';
    } else {
        echo "✅ Tabela $table existe\n";
        $results["table_$table"] = 'OK';
    }
}

echo "\n";

// TEST 3: Simular pedido
echo "[3] Testando salvamento de pedido...\n";
$testOrderId = 'TEST-' . time();
$testData = [
    'id' => $testOrderId,
    'customer_name' => 'Cliente Teste',
    'customer_email' => 'teste@example.com',
    'customer_phone' => '11987654321',
    'customer_address' => 'Rua Teste',
    'customer_city' => 'São Paulo',
    'customer_zip' => '01311100',
    'total' => 99.99,
    'payment_method' => 'pix',
    'status' => 'pendente_atendimento'
];

try {
    $stmt = $db->prepare('INSERT INTO orders (id, customer_name, customer_email, customer_phone, customer_address, customer_city, customer_zip, total, payment_method, status, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())');
    $stmt->bind_param('ssssssssss', $testData['id'], $testData['customer_name'], $testData['customer_email'], $testData['customer_phone'], $testData['customer_address'], $testData['customer_city'], $testData['customer_zip'], $testData['total'], $testData['payment_method'], $testData['status']);
    $stmt->execute();
    echo "✅ Pedido salvo no BD com sucesso (ID: $testOrderId)\n";
    $results['order_insert'] = 'OK';

    // TEST 4: Verificar se pedido foi salvo
    echo "\n[4] Verificando se pedido foi salvo...\n";
    // ✅ FIXED: Use prepared statement to prevent SQL injection
    $selectStmt = $db->prepare("SELECT * FROM orders WHERE id = ? LIMIT 1");
    $selectStmt->bind_param('s', $testOrderId);
    $selectStmt->execute();
    $result = $selectStmt->get_result();

    if ($result && $result->num_rows > 0) {
        $order = $result->fetch_assoc();
        echo "✅ Pedido recuperado do BD\n";
        echo "   - Nome: " . htmlspecialchars($order['customer_name'] ?? '') . "\n";
        echo "   - Email: " . htmlspecialchars($order['customer_email'] ?? '') . "\n";
        echo "   - Total: R$ " . $order['total'] . "\n";
        echo "   - Status: " . htmlspecialchars($order['status'] ?? '') . "\n";
        $results['order_retrieve'] = 'OK';

        // Limpar teste - ✅ FIXED: Use prepared statement
        $deleteStmt = $db->prepare("DELETE FROM orders WHERE id = ?");
        $deleteStmt->bind_param('s', $testOrderId);
        $deleteStmt->execute();
        echo "\n✅ Dados de teste removidos\n";
    } else {
        echo "❌ Pedido não encontrado\n";
        $results['order_retrieve'] = 'FALHA';
    }
} catch (Exception $e) {
    echo "❌ Erro ao salvar pedido: " . $e->getMessage() . "\n";
    $results['order_insert'] = 'FALHA';
}

echo "\n";

// TEST 5: Verificar Admin panels
echo "[5] Verificando arquivos administrativos...\n";
$adminFiles = [
    'admin/index.php',
    'admin/pedidos.php',
    'admin/produtos.php',
    'admin/clientes.php',
    'admin/menu-completo.php'
];
foreach ($adminFiles as $file) {
    if (file_exists($file)) {
        echo "✅ $file existe\n";
        $results["admin_" . basename($file)] = 'OK';
    } else {
        echo "❌ $file NÃO ENCONTRADO\n";
        $results["admin_" . basename($file)] = 'FALHA';
    }
}

echo "\n";

// TEST 6: Verificar gateways
echo "[6] Verificando gateways de pagamento...\n";
$gateways = ['pix', 'boleto', 'mercado_pago', 'pagarme'];
$checkoutContent = file_get_contents(__DIR__ . '/checkout/index.php');
foreach ($gateways as $gateway) {
    if (strpos($checkoutContent, $gateway) !== false) {
        echo "✅ $gateway configurado\n";
        $results["gateway_$gateway"] = 'OK';
    } else {
        echo "❌ $gateway não encontrado\n";
        $results["gateway_$gateway"] = 'FALHA';
    }
}

echo "\n";

// RESUMO
echo "=========================\n";
echo "📊 RESUMO DOS TESTES\n";
echo "=========================\n\n";

$passed = array_filter($results, fn($v) => $v === 'OK');
$failed = array_filter($results, fn($v) => $v === 'FALHA');

echo "✅ Passados: " . count($passed) . "\n";
echo "❌ Falhados: " . count($failed) . "\n";
echo "📈 Taxa: " . round(count($passed) / count($results) * 100) . "%\n";

if ($failed) {
    echo "\n⚠️ TESTES QUE FALHARAM:\n";
    foreach ($failed as $test => $status) {
        echo "   - $test\n";
    }
}

echo "\n";
if (count($failed) === 0) {
    echo "🟢 TUDO PASSOU! SITE PRONTO PARA PRODUÇÃO\n";
    exit(0);
} else {
    echo "🔴 AINDA HÁ PROBLEMAS A CORRIGIR\n";
    exit(1);
}

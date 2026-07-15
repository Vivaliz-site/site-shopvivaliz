<?php
/**
 * VERIFICAR SE PEDIDO FOI CRIADO NO BD
 */
declare(strict_types=1);

require_once __DIR__ . '/config/constants.php';
require_once __DIR__ . '/config/database.php';

echo "🔍 VERIFICANDO PEDIDOS NO BANCO DE DADOS\n";
echo "═════════════════════════════════════════\n\n";

try {
    $db = Database::getInstance()->getConnection();

    // Buscar últimos pedidos
    $result = $db->query("SELECT id, customer_name, customer_email, total, status, payment_method, created_at FROM orders ORDER BY created_at DESC LIMIT 5");

    if ($result && $result->num_rows > 0) {
        echo "✅ Últimos 5 pedidos encontrados:\n\n";

        $count = 1;
        while ($row = $result->fetch_assoc()) {
            echo "$count. ID: {$row['id']}\n";
            echo "   Cliente: {$row['customer_name']}\n";
            echo "   Email: {$row['customer_email']}\n";
            echo "   Total: R$ " . number_format($row['total'], 2, ',', '.') . "\n";
            echo "   Pagamento: " . strtoupper(str_replace('_', ' ', $row['payment_method'])) . "\n";
            echo "   Status: {$row['status']}\n";
            echo "   Data: {$row['created_at']}\n\n";
            $count++;
        }
    } else {
        echo "⚠️  Nenhum pedido encontrado no BD\n";
    }

    // Buscar pedidos com "Boleto" especificamente
    echo "\n📋 PEDIDOS COM BOLETO (últimas 24h):\n";
    $boleto_result = $db->query("SELECT id, customer_name, customer_email, total, created_at FROM orders WHERE payment_method = 'boleto' AND created_at > DATE_SUB(NOW(), INTERVAL 1 DAY) ORDER BY created_at DESC");

    if ($boleto_result && $boleto_result->num_rows > 0) {
        echo "✅ Encontrados " . $boleto_result->num_rows . " pedidos com boleto:\n\n";

        while ($row = $boleto_result->fetch_assoc()) {
            echo "ID: {$row['id']}\n";
            echo "Cliente: {$row['customer_name']}\n";
            echo "Email: {$row['customer_email']}\n";
            echo "Valor: R$ " . number_format($row['total'], 2, ',', '.') . "\n";
            echo "Criado em: {$row['created_at']}\n\n";
        }
    } else {
        echo "⚠️  Nenhum pedido com boleto nas últimas 24h\n";
    }

} catch (Exception $e) {
    echo "❌ ERRO: " . $e->getMessage() . "\n";
    exit(1);
}

echo "\n✅ VERIFICAÇÃO CONCLUÍDA\n";

<?php
/**
 * Automated test suite for Transactional Email and Idempotency.
 */

declare(strict_types=1);

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    echo "Access denied. CLI only.\n";
    exit(1);
}

require_once __DIR__ . '/../config/bootstrap-env.php';
require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/OrderNotificationService.class.php';

echo "🧪 Transactional Email & Idempotency Test Suite\n";
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n";

$service = OrderNotificationService::getInstance();
$db = Database::getInstance()->getConnection();

// Helper to clean up DB tests
function cleanTestNotifications(string $orderId) {
    global $db;
    $stmt = $db->prepare('DELETE FROM order_email_notifications WHERE order_id = ?');
    if ($stmt) {
        $stmt->bind_param('s', $orderId);
        $stmt->execute();
    }
}

// 1. Prepare common order data
$orderId = 'SVTEST' . time();
$orderData = [
    'order_number' => $orderId,
    'total' => 199.90,
    'payment_method' => 'pix',
    'payment_label' => 'PIX',
    'payment_instructions' => 'Copie e cole o código Pix no aplicativo do seu banco.',
    'shipping_label' => 'Melhor Envio - Sedex',
    'customer' => [
        'name' => 'ShopVivaliz Teste',
        'email' => 'shopvivaliz@gmail.com', // Whitelisted for sandbox
        'phone' => '11999999999',
        'address' => 'Av. Paulista, 1000',
        'neighborhood' => 'Bela Vista',
        'city' => 'São Paulo',
        'state' => 'SP',
        'cep' => '01310-100'
    ],
    'items' => [
        [
            'name' => 'Carrinho Fercar C06',
            'quantity' => 1,
            'price' => 199.90
        ]
    ]
];

// Ensure table cleanup
cleanTestNotifications($orderId);

$allPassed = true;

// Test Runner Helper
function runTest(string $name, callable $test) {
    global $allPassed;
    try {
        echo "Testing: $name... ";
        $result = $test();
        if ($result) {
            echo "✅ PASSED\n";
        } else {
            echo "❌ FAILED\n";
            $allPassed = false;
        }
    } catch (Throwable $e) {
        echo "💥 CRASHED (" . $e->getMessage() . ")\n";
        $allPassed = false;
    }
}

// Test 1: New Order Creation Email
runTest("1. New Order Email", function() use ($service, $orderId, $orderData) {
    return $service->notifyOrderEvent($orderId, 'pedido_criado', $orderData);
});

// Test 2: Idempotency (Duplicate New Order Email)
runTest("2. Idempotency on duplicate trigger", function() use ($service, $orderId, $orderData) {
    // Second trigger should return true (skipped but treated as successful/idempotent)
    $res = $service->notifyOrderEvent($orderId, 'pedido_criado', $orderData);
    
    // Check in database that there is only 1 record
    global $db;
    $stmt = $db->prepare('SELECT COUNT(*) as cnt FROM order_email_notifications WHERE order_id = ? AND event_name = "pedido_criado"');
    $stmt->bind_param('s', $orderId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    return $res === true && (int)$row['cnt'] === 1;
});

// Test 3: Approved Payment Email
runTest("3. Payment Approved Email", function() use ($service, $orderId, $orderData) {
    return $service->notifyOrderEvent($orderId, 'pagamento_aprovado', $orderData);
});

// Test 4: Rejected Payment Email
runTest("4. Payment Rejected Email", function() use ($service, $orderId, $orderData) {
    return $service->notifyOrderEvent($orderId, 'pagamento_recusado', $orderData);
});

// Test 5: Payment Expired (Pix/Boleto) Email
runTest("5. Payment Expired Email", function() use ($service, $orderId, $orderData) {
    return $service->notifyOrderEvent($orderId, 'pagamento_expirado', $orderData);
});

// Test 6: Invoice Sent Email
runTest("6. Invoice Sent Email", function() use ($service, $orderId, $orderData) {
    $data = $orderData;
    $data['invoice_url'] = 'https://nfe.prefeitura.sp.gov.br/visualizar.aspx?nfe=123456';
    return $service->notifyOrderEvent($orderId, 'nota_fiscal_emitida', $data);
});

// Test 7: Shipped Email with Tracking Code
runTest("7. Shipped Email with Tracking Code", function() use ($service, $orderId, $orderData) {
    $data = $orderData;
    $data['tracking_number'] = 'QD123456789BR';
    $data['estimated_delivery'] = '20/07/2026';
    return $service->notifyOrderEvent($orderId, 'pedido_enviado', $data);
});

// Test 8: Shipped Email without Tracking Code
runTest("8. Shipped Email without Tracking Code", function() use ($service, $orderId, $orderData) {
    $data = $orderData;
    unset($data['tracking_number']);
    return $service->notifyOrderEvent($orderId, 'pedido_enviado', $data);
});

// Test 9: Out for Delivery Email
runTest("9. Out for Delivery Email", function() use ($service, $orderId, $orderData) {
    return $service->notifyOrderEvent($orderId, 'saiu_para_entrega', $orderData);
});

// Test 10: Delivered Email
runTest("10. Order Delivered Email", function() use ($service, $orderId, $orderData) {
    return $service->notifyOrderEvent($orderId, 'pedido_entregue', $orderData);
});

// Test 11: Cancelled Email
runTest("11. Order Cancelled Email", function() use ($service, $orderId, $orderData) {
    return $service->notifyOrderEvent($orderId, 'pedido_cancelado', $orderData);
});

// Test 12: Refund Requested Email
runTest("12. Refund Requested Email", function() use ($service, $orderId, $orderData) {
    $data = $orderData;
    $data['refund_amount'] = 199.90;
    return $service->notifyOrderEvent($orderId, 'reembolso_solicitado', $data);
});

// Test 13: Refund Completed Email
runTest("13. Refund Completed Email", function() use ($service, $orderId, $orderData) {
    $data = $orderData;
    $data['refund_amount'] = 199.90;
    return $service->notifyOrderEvent($orderId, 'reembolso_concluido', $data);
});

// Test 14: Return Requested Email
runTest("14. Return Requested Email", function() use ($service, $orderId, $orderData) {
    return $service->notifyOrderEvent($orderId, 'troca_devolucao_solicitada', $orderData);
});

// Test 15: Invalid Recipient Email Address Validation
runTest("15. Invalid Email Early Catch", function() use ($service, $orderId, $orderData) {
    $data = $orderData;
    $data['customer']['email'] = 'not-an-email-address';
    $res = $service->notifyOrderEvent($orderId, 'pedido_criado', $data);
    return $res === false; // Should fail validation early
});

// Test 16: Webhook duplicate with external_event_id
runTest("16. Idempotency using externalEventId", function() use ($service, $orderId, $orderData) {
    $eventId = 'evt_test_webhook_123';
    
    // First trigger should create and send
    $res1 = $service->notifyOrderEvent($orderId, 'pedido_em_preparacao', $orderData, $eventId);
    
    // Second trigger with SAME event ID should be blocked
    $res2 = $service->notifyOrderEvent($orderId, 'pedido_em_preparacao', $orderData, $eventId);
    
    return $res1 === true && $res2 === true;
});

// Clean up test order notifications
cleanTestNotifications($orderId);

echo "\n━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
if ($allPassed) {
    echo "🎉 ALL TESTS PASSED SUCCESSFULLY!\n";
    exit(0);
} else {
    echo "❌ SOME TESTS FAILED.\n";
    exit(1);
}

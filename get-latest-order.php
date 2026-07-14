<?php
/**
 * OBTER ÚLTIMO PEDIDO CRIADO
 */
declare(strict_types=1);

require_once __DIR__ . '/config/constants.php';
require_once __DIR__ . '/config/database.php';

try {
    $db = Database::getInstance()->getConnection();

    // Buscar pedido mais recente
    $result = $db->query(
        "SELECT id, customer_name, customer_email, total, payment_method, status, created_at
         FROM orders
         WHERE payment_method = 'boleto'
         ORDER BY created_at DESC
         LIMIT 1"
    );

    if ($result && $result->num_rows > 0) {
        $order = $result->fetch_assoc();
        echo json_encode($order, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    } else {
        echo json_encode(['error' => 'Nenhum pedido com boleto encontrado'], JSON_PRETTY_PRINT);
    }

} catch (Exception $e) {
    echo json_encode(['error' => $e->getMessage()], JSON_PRETTY_PRINT);
}

<?php
require_once __DIR__ . '/config/database.php';
$db = Database::getInstance()->getConnection();
$result = $db->query("SELECT id, customer_name, customer_email, total, payment_method, status, created_at FROM orders WHERE payment_method = 'boleto' ORDER BY created_at DESC LIMIT 1");
if ($result && $result->num_rows > 0) {
    echo json_encode($result->fetch_assoc(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
} else {
    echo json_encode(['error' => 'Nenhum pedido encontrado'], JSON_PRETTY_PRINT);
}
?>

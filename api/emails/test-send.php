<?php
/**
 * Endpoint de teste para envio de email de pedidos
 * Acesso: https://shopvivaliz.com.br/api/emails/test-send.php?email=seu@email.com
 */

declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/api/emails/send-order-notification.php';

header('Content-Type: application/json; charset=utf-8');

// Validar token de segurança (opcional)
$token = $_GET['token'] ?? '';
$expected_token = md5('shopvivaliz_test_' . date('Y-m-d'));

$email = $_GET['email'] ?? 'shopvivaliz@gmail.com';
$event = $_GET['event'] ?? 'order_created';

// Sanitizar email
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Email inválido']);
    exit;
}

// Validar evento
$valid_events = ['order_created', 'payment_received', 'shipping_dispatched', 'boleto_generated', 'status_changed'];
if (!in_array($event, $valid_events)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Evento inválido']);
    exit;
}

// Criar pedido de teste
$test_order = [
    'order_number' => 'SV-TEST-' . date('YmdHis'),
    'created_at' => date('c'),
    'customer' => [
        'name' => 'Teste ShopVivaliz',
        'email' => $email,
        'phone' => '+5537999374112',
        'address' => 'Rua Teste, 123',
        'neighborhood' => 'Centro',
        'city' => 'Minas Gerais',
        'state' => 'MG',
        'cep' => '35501-236',
        'cpf' => '00000000000',
        'street_name' => 'Rua Teste',
        'street_number' => '123'
    ],
    'items' => [
        [
            'name' => 'Produto Teste - ShopVivaliz',
            'quantity' => 1,
            'price' => 99.90,
            'sku' => 'TEST-001',
            'olist_product_id' => ''
        ]
    ],
    'items_total' => 99.90,
    'shipping_total' => 15.00,
    'shipping_label' => 'Frete Padrão',
    'shipping_service' => 'melhor_envio',
    'shipping_cep' => '35501-236',
    'total' => 114.90,
    'payment_method' => 'pix',
    'payment_label' => 'PIX',
    'notes' => 'Pedido de teste para validação de emails',
    'status' => 'pending_confirmation',
    'tracking_code' => ''
];

// Se for boleto ou pagamento, adicionar detalhes
if ($event === 'boleto_generated') {
    $test_order['payment_method'] = 'boleto';
    $test_order['payment_label'] = 'Boleto Bancário';
} elseif ($event === 'shipping_dispatched') {
    $test_order['tracking_code'] = 'BR' . rand(100000000000, 999999999999);
    $test_order['status'] = 'shipped';
}

// Enviar email
try {
    $sent = svem_send_order_email($test_order, $event);

    if ($sent) {
        http_response_code(200);
        echo json_encode([
            'ok' => true,
            'message' => "Email enviado com sucesso para $email!",
            'event' => $event,
            'order_number' => $test_order['order_number'],
            'test_email' => $email,
            'timestamp' => date('Y-m-d H:i:s'),
            'next_step' => "Verifique sua inbox em $email em 1-2 minutos"
        ]);
    } else {
        http_response_code(500);
        echo json_encode([
            'ok' => false,
            'error' => 'Falha ao enviar email',
            'details' => 'Verifique configuração SMTP no .env'
        ]);
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'error' => $e->getMessage(),
        'details' => 'Erro ao processar envio'
    ]);
}

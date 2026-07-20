<?php
/**
 * Send a single persistent test email.
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

$orderId = 'SVTEST-' . rand(1000, 9999);
$orderData = [
    'order_number' => $orderId,
    'total' => 349.90,
    'payment_method' => 'pix',
    'payment_label' => 'PIX',
    'payment_instructions' => 'Copie e cole o código Pix no aplicativo do seu banco.',
    'shipping_label' => 'Melhor Envio - Sedex',
    'customer' => [
        'name' => 'ShopVivaliz Teste Persistente',
        'email' => 'shopvivaliz@gmail.com',
        'phone' => '11999999999',
        'address' => 'Av. Paulista, 1000',
        'neighborhood' => 'Bela Vista',
        'city' => 'São Paulo',
        'state' => 'SP',
        'cep' => '01310-100'
    ],
    'items' => [
        [
            'name' => 'Brinquedo Fercar Trator',
            'quantity' => 1,
            'price' => 349.90
        ]
    ]
];

echo "Sending single persistent test email for order $orderId...\n";
$service = OrderNotificationService::getInstance();
$res = $service->notifyOrderEvent($orderId, 'pedido_criado', $orderData);

if ($res) {
    echo "✅ Email sent successfully. Check your Gmail inbox for shopvivaliz@gmail.com.\n";
} else {
    echo "❌ Failed to send email.\n";
}

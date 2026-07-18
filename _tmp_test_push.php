<?php
require __DIR__ . '/config/bootstrap-env.php';
sv_bootstrap_env();
require __DIR__ . '/includes/tiny-order-push.php';

$order = [
    'order_number' => 'SV_TESTE_CAMPOS_' . time(),
    'payment_method' => 'pix',
    'payment_label' => 'PIX',
    'notes' => 'Teste de payload completo (formaPagamento, idDeposito, numeroOrdemCompra)',
    'shipping_total' => 15.60,
    'customer' => [
        'name' => 'Frederico de Castro Mourao',
        'cpf' => '01366995619',
        'email' => 'shopvivaliz@gmail.com',
    ],
    'items' => [
        ['olist_product_id' => 341440872, 'quantity' => 1, 'price' => 45.00],
    ],
];

try {
    $id = svtop_push_order_tiny($order);
    echo "OK: pedido criado, id=$id\n";
} catch (Throwable $e) {
    echo "ERRO: " . $e->getMessage() . "\n";
}

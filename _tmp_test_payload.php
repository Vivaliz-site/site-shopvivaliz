<?php
require __DIR__ . '/config/bootstrap-env.php';
sv_bootstrap_env();
require __DIR__ . '/includes/tiny-order-push.php';

$token = svtop_tiny_get_token();
$payload = [
    'numeroOrdemCompra' => 'SV_TESTE_PAYLOAD_' . time(),
    'situacao' => 0,
    'idContato' => 893859788,
    'deposito' => ['id' => 337683271],
    'itens' => [
        ['produto' => ['id' => 341440872], 'quantidade' => 1, 'valorUnitario' => 45.00],
    ],
    'valorFrete' => 15.60,
    'obs' => 'Teste isolado de payload (situacao/deposito/pagamento)',
    'pagamento' => ['formaRecebimento' => ['id' => 337683284]], // Pix
];

$res = svtop_tiny_request('POST', '/pedidos', $token, $payload);
echo "STATUS: {$res['status']}\n";
echo $res['body'] . "\n";

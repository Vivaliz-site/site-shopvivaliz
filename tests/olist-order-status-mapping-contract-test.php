<?php
declare(strict_types=1);

$text = (string)file_get_contents(dirname(__DIR__) . '/api/webhooks/order-status-update.php');
$expected = [
    "'aberto' => 'aguardando_pagamento'",
    "'aprovado' => 'pagamento_aprovado'",
    "'preparando_envio' => 'pronto_para_enviar'",
    "'faturado' => 'nota_fiscal_enviada'",
    "'pronto_envio' => 'pronto_para_enviar'",
    "'enviado' => 'enviado'",
    "'entregue' => 'entregue'",
    "'nao_entregue' => 'nao_entregue'",
    "'cancelado' => 'cancelado'",
];
foreach ($expected as $needle) {
    if (!str_contains($text, $needle)) {
        throw new RuntimeException('official Olist order status mapping missing: ' . $needle);
    }
}

echo "olist-order-status-mapping-contract: ok\n";

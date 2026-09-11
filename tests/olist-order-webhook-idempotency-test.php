<?php
declare(strict_types=1);

$source = (string)file_get_contents(dirname(__DIR__) . '/api/webhooks/order-status-update.php');
$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};

$assert(str_contains($source, 'p.olist_order_id'), 'pedido deve carregar id ERP para read-back v3');
$assert(str_contains($source, 'p.nf_id'), 'pedido deve carregar nf_id existente para idempotencia');
$assert(str_contains($source, 'svtop_tiny_get_order'), 'evento interno Olist deve reconciliar pedido pela API v3');
$assert(str_contains($source, 'svoei_order_state_from_v3'), 'read-back v3 deve normalizar estado canonico');
$assert(str_contains($source, '$invoiceChanged'), 'nota fiscal precisa detectar mudanca real');
$assert(str_contains($source, 'if ($invoiceChanged &&'), 'efeitos de NF devem ocorrer apenas em NF nova');
$assert(!str_contains($source, "|| \$invoiceId !== '';"), 'retry de NF nao pode forcar persistencia duplicada');

echo "olist-order-webhook-idempotency: ok\n";

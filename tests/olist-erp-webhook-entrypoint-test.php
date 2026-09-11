<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$endpoint = (string)file_get_contents($root . '/api/olist/webhook.php');
$secret = (string)file_get_contents($root . '/includes/webhook-secret.php');
$order = (string)file_get_contents($root . '/api/webhooks/order-status-update.php');

$assert = static function (bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
};

$assert(str_contains($endpoint, "['tipo']"), 'endpoint deve ler o tipo oficial da Olist');
foreach (['produto', 'precos', 'estoque', 'situacao_pedido', 'rastreio', 'nota_fiscal', 'cotacao'] as $type) {
    $assert(str_contains($endpoint, "'{$type}'"), "endpoint nao roteia {$type}");
}
$assert(str_contains($endpoint, 'svoei_refresh_product_from_v3'), 'produto/preco devem reconciliar pela API v3');
$assert(str_contains($endpoint, 'svoei_refresh_stock_from_v3'), 'estoque deve reconciliar pela API v3');
$assert(!str_contains($endpoint, "require_once __DIR__ . '/webhook-processor.php'"), 'processador legado nao pode receber API do ERP');

$olistBranch = strstr($secret, "if (stripos(\$provider, 'tiny')", true);
$assert(str_contains($secret, "'OLIST_WEBHOOK_SECRET', 'TINY_WEBHOOK_SECRET'"), 'segredos dedicados ausentes');
$assert(!preg_match("/\['OLIST_WEBHOOK_SECRET', 'TINY_WEBHOOK_SECRET', 'SHOPVIVALIZ_AGENT_KEY'\]/", $secret), 'agent key nao pode autenticar webhook ERP');
$assert(str_contains($order, 'SV_OLIST_INTERNAL_AUTHENTICATED'), 'endpoint de pedidos nao aceita despacho interno autenticado');
$assert(str_contains($order, "\$dados['urlRastreio']"), 'urlRastreio oficial nao reconhecida');

echo "olist-erp-webhook-entrypoint: ok\n";

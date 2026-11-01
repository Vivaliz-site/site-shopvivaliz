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

// A retired direct HTTP endpoint must never accept unauthenticated mutations,
// even if the Apache rewrite layer is bypassed by an internal invocation.
$legacyProcessor = (string)file_get_contents($root . '/api/olist/webhook-processor.php');
$legacyInstaller = (string)file_get_contents($root . '/setup-webhooks.php');
$htaccess = (string)file_get_contents($root . '/.htaccess');
$assert(str_contains($legacyProcessor, "http_response_code(410)"), 'legacy processor must return Gone');
$assert(str_contains($legacyProcessor, "'legacy_webhook_retired'"), 'legacy processor must be explicitly retired');
$assert(!preg_match('/\\b(?:UPDATE|INSERT|DELETE)\\s+(?:FROM\\s+|INTO\\s+)?products\\b/i', $legacyProcessor),
    'retired processor must not contain product mutation SQL');
$assert(!str_contains($legacyProcessor, 'mysqli') && !str_contains($legacyProcessor, 'PDO'),
    'retired processor must not connect to local database');
$assert(str_contains($legacyInstaller, "http_response_code(410)"), 'legacy installer must return Gone');
$assert(!str_contains($legacyInstaller, 'file_put_contents'),
    'legacy installer must not modify canonical webhook');
$assert(str_contains($htaccess, 'RewriteRule ^api/olist/webhook-processor\\.php$ - [F,L,NC]'),
    'Apache must block direct legacy processor before PHP is invoked');
$assert(str_contains($endpoint, "sv_webhook_secret_gate('olist', false)"),
    'canonical Olist webhook must authenticate provider requests');

// The production health endpoint must verify the authenticated canonical v3
// ingress and retired legacy writer; the mere existence of the tombstone
// is not a green signal. This is a source-contract negative regression.
$webhookHealth = (string)file_get_contents($root . '/api/olist/webhook-health.php');
foreach ([
    'canonical_entrypoint_readable',
    'canonical_provider_gate_present',
    'canonical_v3_product_reconciliation_present',
    'legacy_processor_retired',
    'legacy_http_blocked',
] as $requiredCheck) {
    $assert(str_contains($webhookHealth, "'{$requiredCheck}'"),
        "webhook health must require {$requiredCheck}");
}
$assert(!str_contains($webhookHealth, "'processor_file_exists'"),
    'webhook health cannot treat the retired processor existence as healthy');
$assert(str_contains($webhookHealth, "\$diagnostics['checks']['database_connected']"),
    'webhook health must keep local database check, without weakening order reconciliation');

echo "olist-erp-webhook-entrypoint: ok\n";

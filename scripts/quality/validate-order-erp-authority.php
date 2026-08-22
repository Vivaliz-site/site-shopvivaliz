<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$failures = [];

function voea_read(string $root, string $rel, array &$failures): string {
    $path = $root . '/' . $rel;
    if (!is_file($path)) {
        $failures[] = "$rel missing";
        return '';
    }
    return (string)file_get_contents($path);
}

$orderPush = voea_read($root, 'includes/tiny-order-push.php', $failures);
$dispatcher = voea_read($root, 'includes/webhook-job-dispatcher.php', $failures);
$nfWebhook = voea_read($root, 'api/webhooks/tiny-nota-fiscal.php', $failures);
$createV2 = voea_read($root, 'api/orders/create-v2.php', $failures);
$processValidated = voea_read($root, 'api/orders/process-validated.php', $failures);

foreach ([
    'includes/tiny-order-push.php' => $orderPush,
    'includes/webhook-job-dispatcher.php' => $dispatcher,
    'api/webhooks/tiny-nota-fiscal.php' => $nfWebhook,
    'api/orders/create-v2.php' => $createV2,
    'api/orders/process-validated.php' => $processValidated,
] as $file => $text) {
    foreach (['api2/', 'pedido.incluir.php', 'TOKEN' . '_API_' . 'OLIST'] as $needle) {
        if (stripos($text, $needle) !== false) {
            $failures[] = "$file contains legacy ERP order/NF source: $needle";
        }
    }
}

if (!str_contains($orderPush, "'https://api.tiny.com.br/public-api/v3'")) {
    $failures[] = 'tiny-order-push.php must use Tiny/Olist public-api/v3 base URL';
}
foreach (["'/pedidos'", "'/pedidos/'", "'/notas/'", "'/contatos'"] as $needle) {
    if (!str_contains($orderPush, trim($needle, '"'))) {
        $failures[] = "tiny-order-push.php missing expected v3 endpoint reference $needle";
    }
}
if (substr_count($dispatcher, 'svtop_push_order_tiny($order)') < 2) {
    $failures[] = 'payment webhooks must push approved Mercado Pago and InfinitePay orders to Tiny v3';
}
if (!str_contains($dispatcher, "\$localStatus === 'payment_approved'")) {
    $failures[] = 'Tiny order push must be gated by payment_approved status';
}
foreach (['api/orders/create-v2.php' => $createV2, 'api/orders/process-validated.php' => $processValidated] as $file => $text) {
    if (str_contains($text, 'svtop_push_order_tiny(')) {
        $failures[] = "$file must not push orders to ERP before payment approval";
    }
    foreach (['pre_payment_draft_mirror', 'tiny_v3_after_payment_approval'] as $needle) {
        if (!str_contains($text, $needle)) {
            $failures[] = "$file must label local order storage as $needle";
        }
    }
}
foreach (['tiny_order_id', 'svtnf_find_order_by_reference', 'generate-label-background.php'] as $needle) {
    if (!str_contains($nfWebhook, $needle)) {
        $failures[] = "NF webhook must depend on Tiny order id / local mirror for label generation: $needle";
    }
}
foreach (['tiny_v3_canonical_after_payment_approval', 'payment_webhook_mirror'] as $needle) {
    if (!str_contains($dispatcher, $needle)) {
        $failures[] = "payment dispatcher must mark ERP/local authority: $needle";
    }
}

if ($failures !== []) {
    fwrite(STDERR, "Order ERP authority validation failed:
- " . implode("
- ", $failures) . "
");
    exit(1);
}

echo "order-erp-authority: ok
";

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

function voea_apply_checkout_gateway_guard(string $root, array &$failures): void {
    $path = $root . '/checkout.php';
    if (!is_file($path)) {
        $failures[] = 'checkout.php missing';
        return;
    }
    $text = (string)file_get_contents($path);
    $bad = "        if (!HAS_PAYMENT_GATEWAY) {\n            status.textContent = 'Pagamento online temporariamente indisponível. Fale no WhatsApp para finalizar seu pedido.';\n            status.className='checkout-status-msg err';\n            return;\n        }\n";
    if (str_contains($text, "function renderCart() {\n        var items = getCart();\n" . $bad)) {
        $text = str_replace($bad, '', $text);
    }
    $anchor = "        var btn = document.getElementById('submit-btn');\n        var status = document.getElementById('checkout-status');\n        var items = getCart();\n";
    $guard = "        if (!HAS_PAYMENT_GATEWAY) {\n            status.textContent = 'Pagamento online temporariamente indisponível. Fale no WhatsApp para finalizar seu pedido.';\n            status.className = 'checkout-status-msg err';\n            if (btn) {\n                btn.disabled = true;\n                btn.textContent = 'Pagamento indisponível';\n            }\n            return;\n        }\n";
    if (!str_contains($text, $anchor)) {
        $failures[] = 'checkout submit anchor missing for gateway guard migration';
        return;
    }
    if (!str_contains($text, $guard)) {
        $text = str_replace($anchor, $anchor . $guard, $text);
    }
    if (str_contains($text, "function renderCart() {\n        var items = getCart();\n        if (!HAS_PAYMENT_GATEWAY)")) {
        $failures[] = 'checkout gateway guard still inside renderCart';
        return;
    }
    if (!str_contains($text, $guard)) {
        $failures[] = 'checkout submit gateway guard missing after migration';
        return;
    }
    file_put_contents($path, $text);
}

voea_apply_checkout_gateway_guard($root, $failures);

$orderPush = voea_read($root, 'includes/tiny-order-push.php', $failures);
$dispatcher = voea_read($root, 'includes/webhook-job-dispatcher.php', $failures);
$nfWebhook = voea_read($root, 'api/webhooks/tiny-nota-fiscal.php', $failures);
$createV2 = voea_read($root, 'api/orders/create-v2.php', $failures);
$processValidated = voea_read($root, 'api/orders/process-validated.php', $failures);
$checkout = voea_read($root, 'checkout.php', $failures);

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
if (str_contains($checkout, "function renderCart() {\n        var items = getCart();\n        if (!HAS_PAYMENT_GATEWAY)")) {
    $failures[] = 'checkout gateway guard must not run inside renderCart';
}
if (!str_contains($checkout, "if (!HAS_PAYMENT_GATEWAY) {\n            status.textContent = 'Pagamento online temporariamente indisponível.")) {
    $failures[] = 'checkout submit must fail closed when no payment gateway is configured';
}

if ($failures !== []) {
    fwrite(STDERR, "Order ERP authority validation failed:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "order-erp-authority: ok\n";

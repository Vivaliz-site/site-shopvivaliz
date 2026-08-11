<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/infinitepay-gateway.php';
require_once dirname(__DIR__) . '/includes/webhook-job-dispatcher.php';

function svip_flow_assert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$orderNumber = 'SV20260810123456789';
$payload = svip_link_payload([
    'order_number' => $orderNumber,
    'items' => [[
        'name' => 'Produto teste',
        'sku' => 'TEST-1',
        'price' => 100.0,
        'quantity' => 1,
    ]],
    'customer' => [],
]);

svip_flow_assert(
    str_contains((string)($payload['redirect_url'] ?? ''), 'order_nsu=' . $orderNumber),
    'InfinitePay redirect must preserve the ShopVivaliz order number.'
);
svip_flow_assert(
    ($payload['order_nsu'] ?? '') === $orderNumber,
    'InfinitePay provider payload must preserve order_nsu.'
);

svip_flow_assert(
    sv_webhook_infinitepay_order_number(['data' => ['order_nsu' => $orderNumber]]) === $orderNumber,
    'Queued webhook parser should recover nested order_nsu.'
);
svip_flow_assert(
    sv_webhook_infinitepay_local_status('paid') === 'payment_approved',
    'Paid InfinitePay event must map to payment_approved.'
);
svip_flow_assert(
    sv_webhook_infinitepay_local_status('refunded') === 'payment_refunded',
    'Refunded InfinitePay event must map to payment_refunded.'
);
svip_flow_assert(
    sv_webhook_infinitepay_local_status('pending') === 'payment_pending',
    'Pending InfinitePay event must remain pending.'
);

$unauthenticated = sv_webhook_job_dispatch_infinitepay([
    'auth_validated' => false,
    'payload' => ['order_nsu' => $orderNumber, 'status' => 'paid'],
]);
svip_flow_assert(
    ($unauthenticated['error'] ?? '') === 'auth_not_validated',
    'Queue jobs without an authenticated edge marker must never update an order.'
);

$source = (string)file_get_contents(dirname(__DIR__) . '/includes/webhook-job-dispatcher.php');
svip_flow_assert(
    !str_contains($source, "function sv_webhook_job_dispatch_infinitepay(array \$payload): array\n{\n    // A autenticacao ja foi validada na borda HTTP antes do enfileiramento.\n    // O job persistente nao deve carregar o token original.\n    return ['success' => true, 'message' => 'processed'];"),
    'InfinitePay dispatcher must not regress to a no-op.'
);

fwrite(STDOUT, "PASS: InfinitePay approved-order flow tests.\n");

<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/payment-notification-idempotency.php';

function svpn_expect(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$order = [
    'order_number' => 'SVTEST001',
    'mercadopago' => ['payment_id' => 'PAY001'],
];

$claimed = svpn_prepare_admin_payment_notification(
    $order,
    'payment_approved',
    'payment_pending',
    '2026-08-02T00:00:00-03:00'
);
svpn_expect($claimed, 'first approval must claim the notification');
svpn_expect(
    ($order['notifications']['admin_payment_received']['status'] ?? '') === 'claimed',
    'claim status must be persisted'
);

$duplicate = svpn_prepare_admin_payment_notification(
    $order,
    'payment_approved',
    'payment_pending',
    '2026-08-02T00:00:01-03:00'
);
svpn_expect(!$duplicate, 'a repeated approval must not claim twice');

svpn_complete_admin_payment_notification(
    $order,
    true,
    '2026-08-02T00:00:02-03:00'
);
svpn_expect(
    ($order['notifications']['admin_payment_received']['status'] ?? '') === 'sent',
    'successful delivery must be recorded as sent'
);

$legacyOrder = [
    'order_number' => 'SVLEGACY001',
    'mercadopago' => ['payment_id' => 'PAYLEGACY001'],
];
$legacyClaimed = svpn_prepare_admin_payment_notification(
    $legacyOrder,
    'payment_approved',
    'payment_approved',
    '2026-08-02T00:00:03-03:00'
);
svpn_expect(!$legacyClaimed, 'an already-approved legacy order must be suppressed');
svpn_expect(
    ($legacyOrder['notifications']['admin_payment_received']['status'] ?? '') === 'suppressed_existing_approval',
    'legacy suppression must leave an audit marker'
);

$pendingOrder = ['order_number' => 'SVPENDING001'];
svpn_expect(
    !svpn_prepare_admin_payment_notification(
        $pendingOrder,
        'payment_pending',
        'payment_pending',
        '2026-08-02T00:00:04-03:00'
    ),
    'non-approved statuses must not claim a notification'
);

$checkoutFile = dirname(__DIR__) . '/checkout.php';
$checkout = file_get_contents($checkoutFile);
svpn_expect(is_string($checkout) && $checkout !== '', 'checkout.php must be readable');
svpn_expect(
    str_contains($checkout, 'var HAS_PAYMENT_GATEWAY ='),
    'checkout must expose a server-side gateway availability flag to JavaScript'
);
$renderCartPos = strpos($checkout, 'function renderCart()');
$submitPos = strpos($checkout, "addEventListener('submit', async function");
svpn_expect($renderCartPos !== false, 'checkout must keep renderCart function');
svpn_expect($submitPos !== false, 'checkout must keep the async submit handler');
$renderCartBlock = substr($checkout, (int)$renderCartPos, max(0, (int)$submitPos - (int)$renderCartPos));
svpn_expect(
    !str_contains($renderCartBlock, 'Pagamento online temporariamente indisponível'),
    'gateway unavailable guard must not run inside renderCart because checkout-status is out of scope there'
);
$submitBlock = substr($checkout, (int)$submitPos, 1200);
svpn_expect(
    str_contains($submitBlock, 'HAS_PAYMENT_GATEWAY')
        && str_contains($submitBlock, 'Pagamento online temporariamente indisponível'),
    'async submit handler must fail closed when online payment gateways are unavailable'
);

echo "OK: admin payment notification idempotency and checkout payment fallback\n";

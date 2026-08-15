<?php

declare(strict_types=1);

require_once __DIR__ . '/first-purchase-reward.php';

function svpn_process_commercial_rewards(array &$order, string $localStatus, string $currentStatus): void
{
    if ($localStatus !== 'payment_approved' || $currentStatus === 'payment_approved') {
        return;
    }

    $email = svcp_normalize_email((string)($order['customer']['email'] ?? ''));
    $orderNumber = trim((string)($order['order_number'] ?? ''));
    $couponCode = strtoupper(trim((string)($order['coupon_code'] ?? '')));

    // Todo cupom usa o mesmo motor de consumo. O uso so e contabilizado apos
    // pagamento aprovado; pedido criado/abandonado nao queima beneficio.
    if ($couponCode !== '' && $orderNumber !== '') {
        svcp_mark_redeemed($couponCode, $email, $orderNumber);
    }

    // Regra adicional sobre o mesmo motor: na primeira compra realmente paga,
    // emite 5% pessoal por 30 dias e dispara o e-mail promocional.
    try {
        $reward = svfpr_issue_for_approved_order($order);
        $order['rewards'] = is_array($order['rewards'] ?? null) ? $order['rewards'] : [];
        $order['rewards']['first_purchase'] = [
            'eligible' => (bool)($reward['eligible'] ?? false),
            'created' => (bool)($reward['created'] ?? false),
            'coupon_code' => (string)($reward['code'] ?? ''),
            'expires_at' => (string)($reward['expires_at'] ?? ''),
            'email_sent' => (bool)($reward['email_sent'] ?? false),
            'processed_at' => date(DATE_ATOM),
        ];
    } catch (Throwable $e) {
        error_log('[PaymentRewards] first purchase reward failed order=' . $orderNumber . ' ' . $e->getMessage());
    }
}

/**
 * Reserves the admin payment-approved notification once per order.
 */
function svpn_prepare_admin_payment_notification(
    array &$order,
    string $localStatus,
    string $currentStatus,
    ?string $occurredAt = null
): bool {
    if ($localStatus !== 'payment_approved') {
        return false;
    }

    svpn_process_commercial_rewards($order, $localStatus, $currentStatus);

    $occurredAt = $occurredAt ?: date(DATE_ATOM);
    $order['notifications'] = is_array($order['notifications'] ?? null)
        ? $order['notifications']
        : [];

    $existing = is_array($order['notifications']['admin_payment_received'] ?? null)
        ? $order['notifications']['admin_payment_received']
        : [];

    if (
        trim((string)($existing['status'] ?? '')) !== ''
        || trim((string)($existing['claimed_at'] ?? '')) !== ''
        || trim((string)($existing['sent_at'] ?? '')) !== ''
    ) {
        return false;
    }

    if ($currentStatus === 'payment_approved') {
        $order['notifications']['admin_payment_received'] = [
            'status' => 'suppressed_existing_approval',
            'suppressed_at' => $occurredAt,
        ];
        return false;
    }

    $orderNumber = trim((string)($order['order_number'] ?? ''));
    $paymentId = trim((string)($order['mercadopago']['payment_id'] ?? $order['infinitepay']['payment_id'] ?? ''));

    $order['notifications']['admin_payment_received'] = [
        'status' => 'claimed',
        'claimed_at' => $occurredAt,
        'attempts' => 1,
        'idempotency_key' => hash('sha256', $orderNumber . '|' . $paymentId . '|admin_payment_received'),
    ];

    return true;
}

function svpn_complete_admin_payment_notification(
    array &$order,
    bool $sent,
    ?string $occurredAt = null
): void {
    $notification = is_array($order['notifications']['admin_payment_received'] ?? null)
        ? $order['notifications']['admin_payment_received']
        : [];

    if (($notification['status'] ?? '') !== 'claimed') {
        return;
    }

    $occurredAt = $occurredAt ?: date(DATE_ATOM);
    $notification['status'] = $sent ? 'sent' : 'failed';
    $notification['completed_at'] = $occurredAt;

    if ($sent) {
        $notification['sent_at'] = $occurredAt;
    } else {
        $notification['failed_at'] = $occurredAt;
    }

    $order['notifications']['admin_payment_received'] = $notification;
}

<?php

declare(strict_types=1);

/**
 * Reserves the admin payment-approved notification once per order.
 *
 * The reservation is persisted before SMTP is called. This intentionally
 * provides at-most-once delivery: a replayed webhook cannot send another
 * message, even if the worker stops after the provider accepted the email.
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

    // Existing approved orders predate the marker. Suppress them instead
    // of emitting an additional email during the next webhook replay.
    if ($currentStatus === 'payment_approved') {
        $order['notifications']['admin_payment_received'] = [
            'status' => 'suppressed_existing_approval',
            'suppressed_at' => $occurredAt,
        ];
        return false;
    }

    $orderNumber = trim((string)($order['order_number'] ?? ''));
    $paymentId = trim((string)($order['mercadopago']['payment_id'] ?? ''));

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

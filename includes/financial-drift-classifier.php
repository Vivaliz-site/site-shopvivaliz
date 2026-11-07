<?php
declare(strict_types=1);

/**
 * Pure financial drift classifier: caller supplies evidence already read from
 * Mercado Pago and Olist/Tiny. No side effects or network calls in this file.
 * This is diagnostic, NOT authorization to refund, ship, invoice or reopen.
 */
function svfd_classify(array $order, array $payment, array $erp): string
{
    $source = (string)($order['source'] ?? '');
    if (!str_starts_with($source, 'site_checkout') || !empty($order['test_order'])) {
        return 'excluded';
    }

    $reference = trim((string)($order['order_number'] ?? ''));
    if ($reference === '' || !hash_equals($reference, (string)($payment['external_reference'] ?? ''))) {
        return 'unverified';
    }

    $localAmount = $order['total'] ?? null;
    $providerAmount = $payment['transaction_amount'] ?? null;
    if (!is_numeric($localAmount) || !is_numeric($providerAmount)
        || (float)$localAmount <= 0
        || abs((float)$localAmount - (float)$providerAmount) >= 0.015
        || (string)($payment['currency_id'] ?? '') !== 'BRL'
        || (string)($payment['status'] ?? '') !== 'approved') {
        return 'unverified';
    }

    // A refund or chargeback must not be classified as an unrefunded payment.
    $refunds = $payment['refunds'] ?? [];
    if ((float)($payment['transaction_amount_refunded'] ?? 0) > 0
        || (is_array($refunds) && $refunds !== [])) {
        return 'requires_review';
    }

    $rawErpState = $erp['situacao'] ?? null;
    if (!is_scalar($rawErpState) || !preg_match('/^[0-9]+$/D', (string)$rawErpState)) {
        return 'unverified';
    }

    $erpState = match ((int)$rawErpState) {
        0 => 'open',
        1 => 'invoiced',
        2 => 'cancelled',
        3 => 'approved',
        4 => 'preparing',
        5 => 'sent',
        6 => 'delivered',
        7 => 'ready_to_ship',
        8 => 'incomplete',
        9 => 'not_delivered',
        default => 'unknown',
    };

    if ((string)($order['status'] ?? '') !== 'payment_approved') {
        return 'discrepancy';
    }

    if (in_array($erpState, ['cancelled', 'not_delivered'], true)) {
        return 'discrepancy';
    }
    if (in_array($erpState, ['approved', 'invoiced', 'preparing', 'ready_to_ship', 'sent', 'delivered'], true)) {
        return 'matched';
    }
    return 'pending';
}

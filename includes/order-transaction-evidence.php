<?php
declare(strict_types=1);

function svote_normalize_payment_status(string $status): string
{
    $status = strtolower(trim($status));
    return match ($status) {
        'approved', 'payment_approved' => 'approved',
        'cancelled', 'canceled', 'rejected', 'failed', 'payment_failed', 'payment_cancelled' => 'failed',
        'refunded', 'chargeback', 'payment_refunded', 'payment_chargeback' => 'refunded',
        default => 'pending',
    };
}

function svote_reconciliation_state(string $paymentStatus, string $erpState): string
{
    $payment = svote_normalize_payment_status($paymentStatus);
    $erp = strtolower(trim($erpState));

    if ($payment !== 'approved') {
        return in_array($erp, ['cancelled', 'canceled', 'not_delivered'], true) ? 'matched' : 'pending';
    }
    if (in_array($erp, ['cancelled', 'canceled', 'not_delivered'], true)) {
        return 'discrepancy';
    }
    if (in_array($erp, ['approved', 'preparing', 'invoiced', 'ready_to_ship', 'sent', 'delivered'], true)) {
        return 'matched';
    }
    return 'pending';
}

function svote_sanitize_payment_snapshot(array $snapshot): array
{
    $allowed = ['provider', 'status', 'provider_id', 'status_detail', 'amount', 'currency', 'topic'];
    $clean = [];
    foreach ($allowed as $key) {
        if (!array_key_exists($key, $snapshot) || is_array($snapshot[$key]) || is_object($snapshot[$key])) {
            continue;
        }
        $value = trim((string)$snapshot[$key]);
        if ($value === '') continue;
        $clean[$key] = substr($value, 0, 255);
    }
    return $clean;
}

function svote_record_payment(PDO $pdo, string $orderNumber, array $snapshot): void
{
    $clean = svote_sanitize_payment_snapshot($snapshot);
    $status = svote_normalize_payment_status((string)($clean['status'] ?? 'pending'));
    $providerId = (string)($clean['provider_id'] ?? '');
    $encoded = json_encode($clean, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $stmt = $pdo->prepare(
        'UPDATE orders SET payment_provider_status=:status, payment_provider_id=NULLIF(:provider_id, ""), payment_evidence_json=:evidence, payment_evidence_at=NOW() WHERE order_number=:order_number'
    );
    $stmt->execute([':status'=>$status, ':provider_id'=>$providerId, ':evidence'=>$encoded ?: '{}', ':order_number'=>$orderNumber]);
}

function svote_record_reconciliation(PDO $pdo, string $orderNumber, string $provider, string $erpState, array $details = []): void
{
    $lookup = $pdo->prepare('SELECT payment_provider_status FROM orders WHERE order_number=:order_number LIMIT 1');
    $lookup->execute([':order_number' => $orderNumber]);
    $paymentStatus = (string)($lookup->fetchColumn() ?: 'pending');
    $state = svote_reconciliation_state($paymentStatus, $erpState);
    $safeDetails = [];
    foreach (['erp_order_id', 'erp_status', 'invoice_id'] as $key) {
        if (isset($details[$key]) && !is_array($details[$key]) && !is_object($details[$key])) {
            $safeDetails[$key] = substr(trim((string)$details[$key]), 0, 255);
        }
    }
    $safeDetails['provider'] = substr(trim($provider), 0, 80);
    $safeDetails['state'] = substr(trim($erpState), 0, 80);
    $encoded = json_encode($safeDetails, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $stmt = $pdo->prepare(
        'UPDATE orders SET reconciliation_status=:status, reconciliation_json=:details, reconciliation_checked_at=NOW() WHERE order_number=:order_number'
    );
    $stmt->execute([':status'=>$state, ':details'=>$encoded ?: '{}', ':order_number'=>$orderNumber]);
}

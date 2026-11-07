#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Read-only P0 diagnostic: verify real Mercado Pago approvals against the
 * corresponding official Olist/Tiny v3 order status.
 *
 * This file never creates, refunds, updates or reopens financial records.
 * It emits only aggregate evidence (no order numbers, PII, IDs or tokens).
 * Exit 2 means a *confirmed* approved payment has conflicting ERP state.
 * Exit 3 means the result is incomplete; never report it as financial parity.
 */

require_once dirname(__DIR__, 2) . '/includes/order-transaction-evidence.php';

function svfra_erp_status(array $response): ?string
{
    if ((int)($response['status'] ?? 0) !== 200) {
        return null;
    }
    $json = $response['json'] ?? null;
    if (!is_array($json)) {
        return null;
    }
    $payload = is_array($json['data'] ?? null) ? $json['data'] : $json;
    $status = $payload['situacao'] ?? null;
    if ((!is_int($status) && !is_string($status)) || trim((string)$status) === '') {
        return null;
    }
    return (string)$status;
}

function svfra_approved_payment(array $payment, string $orderNumber, float $orderTotal): bool
{
    if ($orderNumber === '' || $orderTotal <= 0.0 || trim((string)($payment['external_reference'] ?? '')) !== $orderNumber) {
        return false;
    }
    if (strtolower(trim((string)($payment['status'] ?? ''))) !== 'approved') {
        return false;
    }
    if (abs((float)($payment['transaction_amount'] ?? 0) - $orderTotal) > 0.01) {
        return false;
    }
    if ((float)($payment['transaction_amount_refunded'] ?? 0) > 0.0) {
        return false;
    }
    $refunds = $payment['refunds'] ?? [];
    if (is_array($refunds) && count($refunds) > 0) {
        return false;
    }
    return true;
}

function svfra_audit_live(int $limit): int
{
    require_once dirname(__DIR__, 2) . '/config/bootstrap-env.php';
    require_once dirname(__DIR__, 2) . '/includes/pdo-database.php';
    require_once dirname(__DIR__, 2) . '/includes/mercadopago-gateway.php';
    require_once dirname(__DIR__, 2) . '/includes/tiny-order-push.php';

    $stats = [
        'orders_considered' => 0,
        'provider_verified_approved' => 0,
        'erp_discrepancy' => 0,
        'erp_matched' => 0,
        'erp_pending' => 0,
        'unverified_provider' => 0,
        'provider_lookup_error' => 0,
        'erp_lookup_error' => 0,
    ];
    $pdo = sv_pdo();
    $mpToken = svmp_env('MERCADOPAGO_ACCESS_TOKEN');
    $olistToken = svtop_tiny_get_token();
    if (!$pdo instanceof PDO || $mpToken === '' || $olistToken === '') {
        echo json_encode(['ok' => false, 'status' => 'RUNTIME_UNAVAILABLE', 'has_db' => $pdo instanceof PDO,
            'has_mp_auth' => $mpToken !== '', 'has_erp_auth' => $olistToken !== ''], JSON_UNESCAPED_SLASHES) . PHP_EOL;
        return 3;
    }

    try {
        $stmt = $pdo->prepare("SELECT order_number, order_status, payment_provider_id, olist_order_id
            FROM orders
            WHERE olist_order_id IS NOT NULL AND olist_order_id <> ''
              AND (order_status = 'pagamento_aprovado' OR payment_provider_status = 'approved')
            ORDER BY updated_at DESC LIMIT :max_rows");
        $stmt->bindValue(':max_rows', $limit, PDO::PARAM_INT);
        $stmt->execute();
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        echo json_encode(['ok' => false, 'status' => 'DB_SELECT_FAILED', 'error_type' => get_class($e)]) . PHP_EOL;
        return 3;
    }
    if (!is_array($rows)) {
        echo json_encode(['ok' => false, 'status' => 'DB_RESULT_UNAVAILABLE']) . PHP_EOL;
        return 3;
    }

    foreach ($rows as $row) {
        $stats['orders_considered']++;
        $number = trim((string)($row['order_number'] ?? ''));
        $erpId = trim((string)($row['olist_order_id'] ?? ''));
        if (!svmp_order_number_is_valid($number) || $erpId === '') {
            $stats['unverified_provider']++;
            continue;
        }
        $path = svmp_find_order_path($number);
        if ($path === '') {
            $stats['unverified_provider']++;
            continue;
        }
        $order = svmp_read_order($path);
        if (trim((string)($order['order_number'] ?? '')) !== $number) {
            $stats['unverified_provider']++;
            continue;
        }
        $paymentId = trim((string)($order['mercadopago']['payment_id'] ?? $row['payment_provider_id'] ?? ''));
        if ($paymentId === '' || !ctype_digit($paymentId)) {
            $stats['unverified_provider']++;
            continue;
        }
        try {
            $payment = svmp_api_request('GET', '/v1/payments/' . rawurlencode($paymentId), $mpToken);
            if (!svfra_approved_payment($payment, $number, (float)($order['total'] ?? 0))) {
                $stats['unverified_provider']++;
                continue;
            }
        } catch (Throwable $e) {
            $stats['provider_lookup_error']++;
            continue;
        }
        $stats['provider_verified_approved']++;

        try {
            $erp = svtop_tiny_get_order($erpId, $olistToken);
            $erpStatus = svfra_erp_status($erp);
            if ($erpStatus === null) {
                $stats['erp_lookup_error']++;
                continue;
            }
            $state = svote_reconciliation_state('approved', $erpStatus);
            if ($state === 'discrepancy') {
                $stats['erp_discrepancy']++;
            } elseif ($state === 'matched') {
                $stats['erp_matched']++;
            } else {
                $stats['erp_pending']++;
            }
        } catch (Throwable $e) {
            $stats['erp_lookup_error']++;
        }
    }

    $incomplete = $stats['unverified_provider'] > 0 || $stats['provider_lookup_error'] > 0
        || $stats['erp_lookup_error'] > 0 || $stats['erp_pending'] > 0;
    $status = $stats['erp_discrepancy'] > 0 ? 'CONFIRMED_FINANCIAL_DISCREPANCY'
        : ($incomplete ? 'INCOMPLETE_NOT_CERTIFIED' : 'NO_DISCREPANCY_IN_VERIFIED_SAMPLE');
    echo json_encode(['ok' => !$incomplete && $stats['erp_discrepancy'] === 0, 'status' => $status,
        'read_only' => true, 'limit' => $limit, 'stats' => $stats],
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    return $stats['erp_discrepancy'] > 0 ? 2 : ($incomplete ? 3 : 0);
}

if (realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? '')) === realpath(__FILE__)) {
    if (PHP_SAPI !== 'cli') {
        http_response_code(403);
        exit(1);
    }
    $limit = 10;
    foreach (array_slice($argv, 1) as $arg) {
        if (!preg_match('/^--limit=(\d{1,3})$/', (string)$arg, $m)) {
            fwrite(STDERR, "usage: php audit-paid-erp-readonly.php [--limit=1..50]\n");
            exit(64);
        }
        $limit = (int)$m[1];
    }
    if ($limit < 1 || $limit > 50) {
        fwrite(STDERR, "limit_out_of_range\n");
        exit(64);
    }
    exit(svfra_audit_live($limit));
}

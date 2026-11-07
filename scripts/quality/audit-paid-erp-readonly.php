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
    if ((float)($payment['transaction_amount_refunded'] ?? 0) > 0.0 || (int)($payment['refund_count'] ?? 0) > 0) {
        return false;
    }
    $refunds = $payment['refunds'] ?? [];
    if (is_array($refunds) && count($refunds) > 0) {
        return false;
    }
    return true;
}

/**
 * Select exactly one matching, approved and unrefunded payment from an official
 * provider search. An incomplete search page or multiple matches are
 * inconclusive: never select an arbitrary payment or label it real revenue.
 */
/**
 * A live-mode approved, exact-reference, unrefunded payment with no ERP order
 * ID is a confirmed integration gap, not a completed or delivered sale.
 */
function svfra_approved_payment_without_erp(array $payment, string $number, float $total, string $erpId): bool
{
    return trim($erpId) === ''
        && ($payment['live_mode'] ?? null) === true
        && svfra_approved_payment($payment, $number, $total);
}

function svfra_search_payment_id(array $response, string $number, float $total): ?string
{
    $results = $response['results'] ?? null;
    if (!is_array($results)) {
        return null;
    }
    $paging = $response['paging'] ?? null;
    if (is_array($paging) && isset($paging['total']) && (int)$paging['total'] > count($results)) {
        return null;
    }
    $ids = [];
    foreach ($results as $candidate) {
        if (!is_array($candidate) || !svfra_approved_payment($candidate, $number, $total)) {
            continue;
        }
        $id = trim((string)($candidate['id'] ?? ''));
        if (!ctype_digit($id)) {
            return null;
        }
        $ids[$id] = true;
    }
    return count($ids) === 1 ? (string)array_key_first($ids) : null;
}

/** Whether the provider response contains an official, exact ERP order link. */
function svfra_erp_reference_matches(array $response, string $number): bool
{
    $json = $response['json'] ?? null;
    if ((int)($response['status'] ?? 0) !== 200 || !is_array($json)) {
        return false;
    }
    $payload = is_array($json['data'] ?? null) ? $json['data'] : $json;
    return trim((string)($payload['numeroOrdemCompra'] ?? '')) === $number;
}

/**
 * Distinguish explicitly marked test orders from unavailable historical
 * archives. A missing archive MUST still be investigated via official
 * reference-verified provider search, not discarded as nonexistent revenue.
 */
function svfra_order_scope(?array $order, string $reference): string
{
    if ($order === null) {
        return 'missing_archive';
    }
    if ((string)($order['order_number'] ?? '') !== $reference) {
        return 'reference_mismatch';
    }
    if (!str_starts_with((string)($order['source'] ?? ''), 'site_checkout')) {
        return 'non_site';
    }
    if (!empty($order['test_order'])) {
        return 'test_order';
    }
    return 'site';
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
        'provider_approved_without_erp' => 0,
        'erp_matched' => 0,
        'erp_pending' => 0,
        'unverified_provider' => 0,
        'provider_lookup_error' => 0,
        'erp_lookup_error' => 0,
        'erp_reference_unverified' => 0,
        'verified_via_reference_search' => 0,
        'test_order_true' => 0,
        'test_orders_excluded' => 0,
        'missing_archive' => 0,
        'excluded_non_site' => 0,
        'test_provenance_unknown' => 0,
        'discrepancy_provenance_unknown' => 0,
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
        $stmt = $pdo->prepare("SELECT order_number, order_total, order_status, payment_provider_id, olist_order_id
            FROM orders
            WHERE (order_status = 'pagamento_aprovado' OR payment_provider_status = 'approved')
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
        if (!svmp_order_number_is_valid($number)) {
            $stats['unverified_provider']++;
            continue;
        }
        $path = svmp_find_order_path($number);
        $order = $path !== '' ? svmp_read_order($path) : [];
        // A missing or inconsistent historical JSON file does not invalidate
        // authoritative MySQL total+reference; its test provenance is unknown.
        $hasTrustedFile = is_array($order)
            && trim((string)($order['order_number'] ?? '')) === $number;
        $scope = svfra_order_scope(is_array($order) && $order !== [] ? $order : null, $number);
        if ($scope === 'test_order') {
            $stats['test_orders_excluded']++;
            $stats['test_order_true']++;
            continue;
        }
        if ($scope === 'non_site') {
            $stats['excluded_non_site']++;
            continue;
        }
        if ($scope === 'reference_mismatch') {
            $stats['unverified_provider']++;
            continue;
        }
        if ($scope === 'missing_archive') {
            $stats['missing_archive']++;
        }
        $provenance = $hasTrustedFile
            ? (!empty($order['test_order']) ? 'test' : 'live_or_unmarked')
            : 'unknown';
        $paymentId = $hasTrustedFile
            ? trim((string)($order['mercadopago']['payment_id'] ?? $row['payment_provider_id'] ?? ''))
            : trim((string)($row['payment_provider_id'] ?? ''));
        $total = (float)($row['order_total'] ?? 0);
        if ($total <= 0.0) {
            $stats['unverified_provider']++;
            continue;
        }

        try {
            if (!ctype_digit($paymentId)) {
                $search = svmp_api_request(
                    'GET',
                    '/v1/payments/search?external_reference=' . rawurlencode($number) . '&limit=10',
                    $mpToken
                );
                $paymentId = svfra_search_payment_id($search, $number, $total) ?? '';
                if ($paymentId === '') {
                    $stats['unverified_provider']++;
                    continue;
                }
                $stats['verified_via_reference_search']++;
            }
            // Search summaries are never enough: always re-verify the official
            // payment ID, status, exact reference, amount and refund status.
            $payment = svmp_api_request('GET', '/v1/payments/' . rawurlencode($paymentId), $mpToken);
            if (!svfra_approved_payment($payment, $number, $total)) {
                $stats['unverified_provider']++;
                continue;
            }
        } catch (Throwable $e) {
            $stats['provider_lookup_error']++;
            continue;
        }
        if ($provenance === 'unknown') {
            $stats['test_provenance_unknown']++;
        }
        $stats['provider_verified_approved']++;

        // Payment proof does not imply fulfilment. An empty ERP order link
        // is audited independently without issuing any ERP API request.
        if ($erpId === '') {
            if (svfra_approved_payment_without_erp($payment, $number, $total, $erpId)) {
                $stats['provider_approved_without_erp']++;
            } else {
                $stats['unverified_provider']++;
            }
            continue;
        }

        try {
            $erp = svtop_tiny_get_order($erpId, $olistToken);
            $erpStatus = svfra_erp_status($erp);
            if ($erpStatus !== null && !svfra_erp_reference_matches($erp, $number)) {
                $stats['erp_reference_unverified']++;
                continue;
            }
            if ($erpStatus === null) {
                $stats['erp_lookup_error']++;
                continue;
            }
            $state = svote_reconciliation_state('approved', $erpStatus);
            if ($state === 'discrepancy') {
                $stats['erp_discrepancy']++;
                if ($provenance === 'unknown') {
                    $stats['discrepancy_provenance_unknown']++;
                }
            } elseif ($state === 'matched') {
                $stats['erp_matched']++;
            } else {
                $stats['erp_pending']++;
            }
        } catch (Throwable $e) {
            $stats['erp_lookup_error']++;
        }
    }

    $incomplete = $stats['orders_considered'] === 0 || $stats['provider_verified_approved'] === 0
        || $stats['unverified_provider'] > 0 || $stats['provider_lookup_error'] > 0
        || $stats['erp_lookup_error'] > 0 || $stats['erp_reference_unverified'] > 0
        || $stats['test_provenance_unknown'] > 0 || $stats['erp_pending'] > 0;
    $confirmedDiscrepancy = $stats['erp_discrepancy'] > 0
        || $stats['provider_approved_without_erp'] > 0;
    $status = $confirmedDiscrepancy ? 'CONFIRMED_FINANCIAL_DISCREPANCY'
        : ($incomplete ? 'INCOMPLETE_NOT_CERTIFIED' : 'NO_DISCREPANCY_IN_VERIFIED_SAMPLE');
    echo json_encode(['ok' => !$incomplete && !$confirmedDiscrepancy, 'status' => $status,
        'read_only' => true, 'limit' => $limit, 'stats' => $stats],
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    return $confirmedDiscrepancy ? 2 : ($incomplete ? 3 : 0);
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

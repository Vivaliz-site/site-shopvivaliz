<?php
declare(strict_types=1);

/**
 * Inspect pending site orders by exact external_reference in Mercado Pago.
 * This is a bounded read-only diagnostic, not proof of all-provider revenue.
 * Run: php scripts/finance/site-pending-gateway-readonly.php --since=2026-08-01
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit(1);
}

function svpr_payment_class(array $payment, string $reference, float $total): string
{
    $status = strtolower(trim((string)($payment['status'] ?? '')));
    if ($status !== 'approved') {
        if (in_array($status, ['pending', 'in_process', 'authorized'], true)) {
            return 'pending';
        }
        if (in_array($status, ['rejected', 'cancelled', 'refunded', 'charged_back'], true)) {
            return 'rejected_or_refunded';
        }
        return 'other_status';
    }

    $refunds = $payment['refunds'] ?? [];
    $hasRefund = (float)($payment['transaction_amount_refunded'] ?? 0) > 0
        || (int)($payment['refund_count'] ?? 0) > 0
        || (is_array($refunds) && count($refunds) > 0);
    if ((string)($payment['external_reference'] ?? '') !== $reference
        || abs((float)($payment['transaction_amount'] ?? 0) - $total) > 0.01
        || ($payment['live_mode'] ?? null) !== true
        || ($payment['currency_id'] ?? null) !== 'BRL'
        || $hasRefund) {
        return 'approved_unverified';
    }
    return 'approved_verified';
}

/**
 * $search($reference) and $detail($id) must use only official provider GETs.
 * Never emit order numbers, customer data, transaction IDs or raw exceptions.
 */
function svpr_inspect_rows(array $rows, callable $search, callable $detail): array
{
    $counts = [
        'site_orders_checked' => count($rows),
        'gateway_scoped' => 0,
        'outside_gateway_scope' => 0,
        'invalid_reference_or_amount' => 0,
        'search_success' => 0,
        'search_errors' => 0,
        'paging_incomplete' => 0,
        'no_payment_found' => 0,
        'provider_results' => 0,
        'detail_errors' => 0,
        'invalid_payment_id' => 0,
        'approved_verified' => 0,
        'approved_unverified' => 0,
        'pending' => 0,
        'rejected_or_refunded' => 0,
        'other_status' => 0,
    ];
    foreach ($rows as $row) {
        $method = strtolower(trim((string)($row['payment_method'] ?? '')));
        if (!in_array($method, ['mercado_pago', 'boleto'], true)) {
            $counts['outside_gateway_scope']++;
            continue;
        }
        $counts['gateway_scoped']++;
        $reference = trim((string)($row['order_number'] ?? ''));
        $amount = (float)($row['order_total'] ?? 0);
        if ($reference === '' || $amount <= 0) {
            $counts['invalid_reference_or_amount']++;
            continue;
        }
        try {
            $response = $search($reference);
            if (!is_array($response) || !isset($response['results']) || !is_array($response['results'])) {
                $counts['search_errors']++;
                continue;
            }
            $counts['search_success']++;
            $items = $response['results'];
            if ((int)($response['paging']['total'] ?? count($items)) > count($items)) {
                $counts['paging_incomplete']++;
            }
            if (count($items) === 0) {
                $counts['no_payment_found']++;
                continue;
            }
            foreach ($items as $item) {
                if (!is_array($item)) {
                    $counts['invalid_payment_id']++;
                    continue;
                }
                $counts['provider_results']++;
                $id = trim((string)($item['id'] ?? ''));
                if (!ctype_digit($id)) {
                    $counts['invalid_payment_id']++;
                    continue;
                }
                try {
                    $payment = $detail($id);
                    if (!is_array($payment)) {
                        $counts['detail_errors']++;
                        continue;
                    }
                    $kind = svpr_payment_class($payment, $reference, $amount);
                    $counts[$kind]++;
                } catch (Throwable $ignored) {
                    $counts['detail_errors']++;
                }
            }
        } catch (Throwable $ignored) {
            $counts['search_errors']++;
        }
    }
    $incomplete = $counts['invalid_reference_or_amount'] > 0
        || $counts['search_errors'] > 0 || $counts['paging_incomplete'] > 0
        || $counts['detail_errors'] > 0 || $counts['invalid_payment_id'] > 0
        || $counts['approved_unverified'] > 0;
    $status = $incomplete
        ? 'INCOMPLETE_NOT_CERTIFIED'
        : ($counts['approved_verified'] > 0
            ? 'APPROVED_PAYMENT_REQUIRES_RECONCILIATION'
            : 'NO_APPROVED_PAYMENT_IN_SCOPED_REFERENCES');
    return [
        'read_only' => true,
        'scope' => 'mercado_pago_external_reference_only',
        'status' => $status,
        'coverage_limited' => $incomplete || $counts['outside_gateway_scope'] > 0,
        'counts' => $counts,
    ];
}

function svpr_run(array $argv): int
{
    $since = null;
    foreach (array_slice($argv, 1) as $arg) {
        if (preg_match('/^--since=(\d{4}-\d{2}-\d{2})$/D', $arg, $match)
            && DateTimeImmutable::createFromFormat('!Y-m-d', $match[1])?->format('Y-m-d') === $match[1]) {
            $since = $match[1];
        } else {
            fwrite(STDERR, "usage: --since=YYYY-MM-DD\n");
            return 3;
        }
    }
    if ($since === null) {
        fwrite(STDERR, "usage: --since=YYYY-MM-DD\n");
        return 3;
    }
    require_once dirname(__DIR__, 2) . '/config/bootstrap-env.php';
    require_once dirname(__DIR__, 2) . '/includes/pdo-database.php';
    require_once dirname(__DIR__, 2) . '/includes/mercadopago-gateway.php';
    $token = svmp_env('MERCADOPAGO_ACCESS_TOKEN');
    $pdo = sv_pdo();
    if (!$pdo instanceof PDO || $token === '') {
        echo json_encode(['read_only' => true, 'status' => 'RUNTIME_NOT_READY']), PHP_EOL;
        return 3;
    }
    $q = $pdo->prepare(
        "SELECT order_number, order_total, payment_method FROM orders
         WHERE created_at >= :since AND order_status = 'aguardando_pagamento'
         ORDER BY created_at LIMIT 100"
    );
    $q->execute([':since' => $since]);
    $rows = $q->fetchAll(PDO::FETCH_ASSOC);
    $report = svpr_inspect_rows(
        $rows,
        static fn(string $ref): array => svmp_api_request(
            'GET', '/v1/payments/search?external_reference=' . rawurlencode($ref) . '&limit=10', $token
        ),
        static fn(string $id): array => svmp_api_request(
            'GET', '/v1/payments/' . rawurlencode($id), $token
        )
    );
    $report['since'] = $since;
    $report['row_limit'] = 100;
    $report['coverage_limited'] = $report['coverage_limited'] || count($rows) >= 100;
    echo json_encode($report, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), PHP_EOL;
    return $report['status'] === 'APPROVED_PAYMENT_REQUIRES_RECONCILIATION' ? 2
        : ($report['coverage_limited'] ? 3 : 0);
}

if (realpath((string)($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    exit(svpr_run($argv));
}

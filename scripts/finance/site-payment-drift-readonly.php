<?php
declare(strict_types=1);

/**
 * Read-only live reconciliation of site checkout payment evidence.
 * Never creates orders, refunds, invoices, labels or database writes.
 *
 * Usage: php scripts/finance/site-payment-drift-readonly.php
 * Output: aggregate counts only (no customer/transaction identifiers).
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit(1);
}

$root = dirname(__DIR__, 2);
require_once $root . '/includes/financial-drift-classifier.php';
require_once $root . '/includes/mercadopago-gateway.php';
require_once $root . '/includes/tiny-order-push.php';

$summary = [
    'read_only' => true,
    'site_orders' => 0,
    'test_orders_excluded' => 0,
    'payment_candidates' => 0,
    'without_provider_id' => 0,
    'provider_verified' => 0,
    'erp_verified' => 0,
    'discrepancy' => 0,
    'matched' => 0,
    'pending' => 0,
    'unverified' => 0,
    'requires_review' => 0,
    'mysql_records_checked' => 0,
    'mysql_missing_evidence' => 0,
    'mysql_unavailable' => 0,
    'api_errors' => 0,
];

$mpToken = svmp_env('MERCADOPAGO_ACCESS_TOKEN');
$erpToken = svtop_tiny_get_token();
if ($mpToken === '' || $erpToken === '') {
    echo json_encode(['status' => 'NOT_READY', 'reason' => 'official_readonly_credentials_missing'], JSON_UNESCAPED_SLASHES) . PHP_EOL;
    exit(2);
}

// In immutable Marina releases, the shared store is a sibling of releases/.
$deployRoot = dirname(dirname((string)(realpath($root) ?: $root)));
$folders = array_unique([
    $deployRoot . '/shared/storage/orders',
    $root . '/storage/orders',
]);
$paths = [];
foreach ($folders as $folder) {
    foreach (glob($folder . '/*.json') ?: [] as $path) {
        $paths[(string)(realpath($path) ?: $path)] = true;
    }
}

$pdo = null;
try {
    require_once $root . '/config/constants.php';
    $pdo = new PDO(
        'mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . DB_NAME . ';charset=utf8mb4',
        DB_USER,
        DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_TIMEOUT => 5]
    );
    $mysql = $pdo->prepare(
        'SELECT payment_provider_status, reconciliation_status FROM orders WHERE order_number=:order_number LIMIT 1'
    );
} catch (Throwable $ignored) {
    $summary['mysql_unavailable']++;
    $pdo = null;
}

$seen = [];
foreach (array_keys($paths) as $path) {
    $order = json_decode((string)file_get_contents($path), true);
    if (!is_array($order) || !str_starts_with((string)($order['source'] ?? ''), 'site_checkout')) {
        continue;
    }
    $reference = trim((string)($order['order_number'] ?? ''));
    if ($reference === '' || isset($seen[$reference])) {
        continue;
    }
    $seen[$reference] = true;
    $summary['site_orders']++;

    if (!empty($order['test_order'])) {
        $summary['test_orders_excluded']++;
        continue;
    }

    $localStatus = (string)($order['status'] ?? '');
    if (!in_array($localStatus, ['payment_approved', 'payment_pending', 'pending_confirmation'], true)) {
        continue;
    }

    $mp = is_array($order['mercadopago'] ?? null) ? $order['mercadopago'] : [];
    $paymentId = (string)($mp['payment_id'] ?? '');
    $erpId = (string)($order['tiny_order_id'] ?? '');
    if (!preg_match('/^[0-9]{6,22}$/D', $paymentId)
        || !preg_match('/^[0-9]{5,22}$/D', $erpId)) {
        $summary['without_provider_id']++;
        continue;
    }
    $summary['payment_candidates']++;

    try {
        // Both paths are GET; no provider or ERP business mutations.
        $payment = svmp_api_request('GET', '/v1/payments/' . rawurlencode($paymentId), $mpToken);
        $summary['provider_verified']++;
        $erpResponse = svtop_tiny_get_order($erpId, $erpToken);
        if ((int)($erpResponse['status'] ?? 0) !== 200) {
            $summary['api_errors']++;
            continue;
        }
        $body = is_array($erpResponse['json'] ?? null) ? $erpResponse['json'] : [];
        $erp = is_array($body['data'] ?? null) ? $body['data'] : $body;
        $summary['erp_verified']++;

        // Validate ERP order belongs to the same site checkout record.
        if (!hash_equals($reference, (string)($erp['numeroOrdemCompra'] ?? ''))) {
            $summary['unverified']++;
            continue;
        }
        $decision = svfd_classify($order, $payment, $erp);
        if (isset($summary[$decision])) {
            $summary[$decision]++;
        } else {
            $summary['unverified']++;
        }

        if ($pdo !== null && $decision === 'discrepancy') {
            $mysql->execute([':order_number' => $reference]);
            $record = $mysql->fetch(PDO::FETCH_ASSOC);
            if (is_array($record)) {
                $summary['mysql_records_checked']++;
                if (empty($record['payment_provider_status'])
                    || empty($record['reconciliation_status'])) {
                    $summary['mysql_missing_evidence']++;
                }
            }
        }
    } catch (Throwable $ignored) {
        // No raw exception to protect customer IDs, API paths and token metadata.
        $summary['api_errors']++;
    }
}

if ($summary['discrepancy'] > 0 || $summary['mysql_missing_evidence'] > 0) {
    $status = 'P0_FINANCIAL_DRIFT_FOUND';
} elseif ($summary['api_errors'] > 0 || $summary['mysql_unavailable'] > 0) {
    $status = 'INCOMPLETE_EVIDENCE';
} else {
    $status = 'NO_VERIFIED_DRIFT_IN_SCANNED_ORDERS';
}
echo json_encode(['status' => $status, 'summary' => $summary], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . PHP_EOL;

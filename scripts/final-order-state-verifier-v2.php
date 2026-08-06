<?php

declare(strict_types=1);

$root = '/home/ubuntu/shopvivaliz-deploy/current';
if (!is_dir($root) || !chdir($root)) {
    throw new RuntimeException('active_release_missing');
}

require_once $root . '/includes/mercadopago-gateway.php';
require_once $root . '/config/database.php';

$orderNumber = trim((string)getenv('ORDER_NUMBER'));
$expectedSha = trim((string)getenv('EXPECTED_SHA'));
if (!svmp_order_number_is_valid($orderNumber) || preg_match('/^[a-f0-9]{40}$/', $expectedSha) !== 1) {
    throw new RuntimeException('invalid_arguments');
}

$path = svmp_find_order_path($orderNumber);
if ($path === '') {
    throw new RuntimeException('order_file_missing');
}
$beforeHash = hash_file('sha256', $path);
$order = svmp_read_order($path);
$paymentId = trim((string)($order['mercadopago']['payment_id'] ?? ''));
$tinyId = trim((string)($order['tiny_order_id'] ?? ''));
$providerStatus = strtolower(trim((string)($order['mercadopago']['status'] ?? '')));

$orderFileCount = 0;
$paymentFileCount = 0;
$seenPaths = [];
foreach (svmp_order_directories() as $directory) {
    foreach (glob($directory . DIRECTORY_SEPARATOR . '*.json') ?: [] as $candidatePath) {
        $real = realpath($candidatePath) ?: $candidatePath;
        if (isset($seenPaths[$real])) {
            continue;
        }
        $seenPaths[$real] = true;
        $candidateOrder = svmp_read_order($candidatePath);
        if (trim((string)($candidateOrder['order_number'] ?? '')) === $orderNumber) {
            $orderFileCount++;
        }
        if ($paymentId !== '' && trim((string)($candidateOrder['mercadopago']['payment_id'] ?? '')) === $paymentId) {
            $paymentFileCount++;
        }
    }
}

$db = Database::getInstance()->getConnection();
if (!$db instanceof mysqli) {
    throw new RuntimeException('database_unavailable');
}
$stmt = $db->prepare('SELECT order_status, olist_order_id FROM orders WHERE order_number = ?');
if (!$stmt instanceof mysqli_stmt) {
    throw new RuntimeException('order_query_prepare_failed');
}
$stmt->bind_param('s', $orderNumber);
$stmt->execute();
$result = $stmt->get_result();
$rows = $result instanceof mysqli_result ? $result->fetch_all(MYSQLI_ASSOC) : [];
if ($result instanceof mysqli_result) {
    $result->free();
}
$stmt->close();

$dbStatuses = [];
$dbTinyIds = [];
foreach ($rows as $row) {
    $status = trim((string)($row['order_status'] ?? ''));
    if ($status !== '') {
        $dbStatuses[$status] = true;
    }
    $dbTinyIdCandidate = trim((string)($row['olist_order_id'] ?? ''));
    if ($dbTinyIdCandidate !== '') {
        $dbTinyIds[$dbTinyIdCandidate] = true;
    }
}
$dbTinyId = count($dbTinyIds) === 1 ? (string)array_key_first($dbTinyIds) : '';
$tinyDuplicateCount = 0;
if ($dbTinyId !== '') {
    $duplicateStmt = $db->prepare('SELECT COUNT(*) AS total FROM orders WHERE olist_order_id = ?');
    if (!$duplicateStmt instanceof mysqli_stmt) {
        throw new RuntimeException('duplicate_query_prepare_failed');
    }
    $duplicateStmt->bind_param('s', $dbTinyId);
    $duplicateStmt->execute();
    $duplicateResult = $duplicateStmt->get_result();
    $duplicateRow = $duplicateResult instanceof mysqli_result ? $duplicateResult->fetch_assoc() : [];
    $tinyDuplicateCount = (int)($duplicateRow['total'] ?? 0);
    if ($duplicateResult instanceof mysqli_result) {
        $duplicateResult->free();
    }
    $duplicateStmt->close();
}

clearstatcache(true, $path);
$afterHash = hash_file('sha256', $path);
$activeSha = is_file($root . '/.release-sha') ? trim((string)file_get_contents($root . '/.release-sha')) : '';

$checks = [
    'exact_release_active' => hash_equals($expectedSha, $activeSha),
    'local_payment_approved' => trim((string)($order['status'] ?? '')) === 'payment_approved',
    'provider_status_approved' => svmp_local_status($providerStatus) === 'payment_approved',
    'webhook_timestamp_present' => trim((string)($order['mercadopago']['last_webhook_at'] ?? '')) !== '',
    'customer_payment_email_recorded' => (bool)($order['payment_confirmation_email_sent'] ?? false),
    'customer_payment_email_timestamped' => trim((string)($order['payment_confirmation_email_sent_at'] ?? '')) !== '',
    'tiny_push_ok' => trim((string)($order['tiny_push'] ?? '')) === 'ok',
    'tiny_id_present' => $tinyId !== '',
    'single_order_file' => $orderFileCount === 1,
    'single_payment_file' => $paymentFileCount === 1,
    'single_database_order' => count($rows) === 1,
    'database_status_approved' => isset($dbStatuses['pagamento_aprovado']),
    'database_tiny_id_present' => $dbTinyId !== '',
    'tiny_ids_match' => $tinyId !== '' && $dbTinyId !== '' && hash_equals($tinyId, $dbTinyId),
    'tiny_id_not_duplicated' => $tinyDuplicateCount === 1,
    'order_file_unchanged' => is_string($beforeHash) && is_string($afterHash) && hash_equals($beforeHash, $afterHash),
];
$failures = array_keys(array_filter($checks, static fn(bool $ok): bool => !$ok));

$report = [
    'ok' => $failures === [],
    'read_only' => true,
    'order_number' => $orderNumber,
    'release_sha' => $activeSha,
    'provider_status_class' => svmp_local_status($providerStatus),
    'order_file_count' => $orderFileCount,
    'payment_file_count' => $paymentFileCount,
    'database_order_count' => count($rows),
    'tiny_id_hash12' => $tinyId !== '' ? substr(hash('sha256', $tinyId), 0, 12) : '',
    'database_tiny_id_hash12' => $dbTinyId !== '' ? substr(hash('sha256', $dbTinyId), 0, 12) : '',
    'checks' => $checks,
    'failures' => $failures,
];

echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
exit($failures === [] ? 0 : 1);

// verification-trigger: 2026-08-06T19:51:14Z

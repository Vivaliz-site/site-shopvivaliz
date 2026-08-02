<?php

declare(strict_types=1);

$root = '/home/ubuntu/shopvivaliz-deploy/current';
if (!is_dir($root) || !chdir($root)) {
    throw new RuntimeException('active_release_missing');
}

require_once $root . '/includes/mercadopago-gateway.php';
require_once $root . '/includes/pdo-database.php';

$orderNumber = trim((string)getenv('ORDER_NUMBER'));
$expectedSha = trim((string)getenv('EXPECTED_SHA'));

function audit_http(string $url): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_HTTPHEADER => ['Accept: application/json', 'User-Agent: ShopVivalizFinalAudit/1.0'],
    ]);
    $body = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $error = curl_errno($ch) !== 0;
    curl_close($ch);
    return ['status' => $status, 'body' => is_string($body) ? $body : '', 'error' => $error];
}

function audit_json(string $url): array
{
    $response = audit_http($url);
    $decoded = json_decode($response['body'], true);
    return [
        'status' => $response['status'],
        'error' => $response['error'],
        'json' => is_array($decoded) ? $decoded : [],
    ];
}

$versionResponse = audit_json('https://shopvivaliz.com.br/api/health/version.php?final_audit=20260802');
$ordersResponse = audit_json('https://shopvivaliz.com.br/api/orders/health.php?final_audit=20260802');
$olistResponse = audit_json('https://shopvivaliz.com.br/api/olist/webhook-health.php?final_audit=20260802');
$lizResponse = audit_json('https://shopvivaliz.com.br/api/liz-intelligent.php?health=1&final_audit=20260802');
$homeResponse = audit_http('https://shopvivaliz.com.br/?final_audit=20260802');
$catalogResponse = audit_http('https://shopvivaliz.com.br/catalogo?final_audit=20260802');
$checkoutResponse = audit_http('https://shopvivaliz.com.br/checkout?final_audit=20260802');
$webhookGetResponse = audit_http('https://shopvivaliz.com.br/api/webhook-mercadopago.php?final_audit=20260802');

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
$seen = [];
foreach (svmp_order_directories() as $directory) {
    foreach (glob($directory . DIRECTORY_SEPARATOR . '*.json') ?: [] as $candidate) {
        $real = realpath($candidate) ?: $candidate;
        if (isset($seen[$real])) {
            continue;
        }
        $seen[$real] = true;
        $candidateOrder = svmp_read_order($candidate);
        if (trim((string)($candidateOrder['order_number'] ?? '')) === $orderNumber) {
            $orderFileCount++;
        }
        if ($paymentId !== '' && trim((string)($candidateOrder['mercadopago']['payment_id'] ?? '')) === $paymentId) {
            $paymentFileCount++;
        }
    }
}

$pdo = sv_pdo();
if (!$pdo instanceof PDO) {
    throw new RuntimeException('database_unavailable');
}
$stmt = $pdo->prepare('SELECT order_status, olist_order_id FROM orders WHERE order_number = :order_number');
$stmt->execute([':order_number' => $orderNumber]);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
$dbStatuses = [];
$dbTinyIds = [];
foreach ($rows as $row) {
    $status = trim((string)($row['order_status'] ?? ''));
    if ($status !== '') {
        $dbStatuses[$status] = true;
    }
    $id = trim((string)($row['olist_order_id'] ?? ''));
    if ($id !== '') {
        $dbTinyIds[$id] = true;
    }
}
$dbTinyId = count($dbTinyIds) === 1 ? (string)array_key_first($dbTinyIds) : '';
$tinyDuplicateCount = 0;
if ($dbTinyId !== '') {
    $dup = $pdo->prepare('SELECT COUNT(*) FROM orders WHERE olist_order_id = :olist_order_id');
    $dup->execute([':olist_order_id' => $dbTinyId]);
    $tinyDuplicateCount = (int)$dup->fetchColumn();
}

clearstatcache(true, $path);
$afterHash = hash_file('sha256', $path);

$version = $versionResponse['json'];
$ordersHealth = $ordersResponse['json'];
$olistHealth = $olistResponse['json'];
$lizHealth = $lizResponse['json'];

$checks = [
    'exact_release_active' => ($version['release_sha'] ?? '') === $expectedSha,
    'version_health_ok' => $versionResponse['status'] === 200 && ($version['ok'] ?? false) === true,
    'orders_health_ok' => $ordersResponse['status'] === 200 && ($ordersHealth['ok'] ?? false) === true,
    'olist_health_ok' => $olistResponse['status'] === 200 && ($olistHealth['ok'] ?? false) === true,
    'liz_health_ok' => $lizResponse['status'] === 200 && ($lizHealth['ok'] ?? false) === true,
    'home_http_200' => $homeResponse['status'] === 200,
    'catalog_http_200' => $catalogResponse['status'] === 200,
    'checkout_http_200' => $checkoutResponse['status'] === 200,
    'webhook_get_rejected' => $webhookGetResponse['status'] === 405,
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
    'order_file_unchanged' => is_string($beforeHash) && hash_equals($beforeHash, (string)$afterHash),
];

$failures = array_keys(array_filter($checks, static fn (bool $ok): bool => !$ok));
$report = [
    'ok' => $failures === [],
    'read_only' => true,
    'order_number' => $orderNumber,
    'release_sha' => (string)($version['release_sha'] ?? ''),
    'provider_status_class' => svmp_local_status($providerStatus),
    'order_file_count' => $orderFileCount,
    'payment_file_count' => $paymentFileCount,
    'database_order_count' => count($rows),
    'tiny_id_hash12' => $tinyId !== '' ? substr(hash('sha256', $tinyId), 0, 12) : '',
    'database_tiny_id_hash12' => $dbTinyId !== '' ? substr(hash('sha256', $dbTinyId), 0, 12) : '',
    'http_statuses' => [
        'version' => $versionResponse['status'],
        'orders' => $ordersResponse['status'],
        'olist' => $olistResponse['status'],
        'liz' => $lizResponse['status'],
        'home' => $homeResponse['status'],
        'catalog' => $catalogResponse['status'],
        'checkout' => $checkoutResponse['status'],
        'webhook_get' => $webhookGetResponse['status'],
    ],
    'checks' => $checks,
    'failures' => $failures,
];

echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
exit($failures === [] ? 0 : 1);

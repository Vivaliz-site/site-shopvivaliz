<?php

declare(strict_types=1);

$root = '/home/ubuntu/shopvivaliz-deploy/current';
if (!is_dir($root) || !chdir($root)) {
    throw new RuntimeException('active_release_missing');
}

require_once $root . '/includes/mercadopago-gateway.php';
require_once $root . '/includes/runtime-env-reader.php';

$orderNumber = trim((string)getenv('ORDER_NUMBER'));
$expectedSha = trim((string)getenv('EXPECTED_SHA'));
if (!svmp_order_number_is_valid($orderNumber) || !preg_match('/^[a-f0-9]{40}$/', $expectedSha)) {
    throw new RuntimeException('invalid_audit_arguments');
}

/** @return array{status:int,json:array<string,mixed>,effective_url:string} */
function svfa_http_json(string $url, bool $follow = false): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_FOLLOWLOCATION => $follow,
        CURLOPT_MAXREDIRS => 5,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_HTTPHEADER => ['Accept: application/json', 'User-Agent: ShopVivalizFinalAudit/2.0'],
    ]);
    $body = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $effectiveUrl = (string)curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
    $error = curl_errno($ch);
    curl_close($ch);
    if ($body === false || $error !== 0) {
        return ['status' => 0, 'json' => [], 'effective_url' => $effectiveUrl];
    }
    $decoded = json_decode((string)$body, true);
    return ['status' => $status, 'json' => is_array($decoded) ? $decoded : [], 'effective_url' => $effectiveUrl];
}

/** @return array{status:int,effective_url:string} */
function svfa_http_status(string $url, bool $follow = false): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_FOLLOWLOCATION => $follow,
        CURLOPT_MAXREDIRS => 5,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_HTTPHEADER => ['User-Agent: ShopVivalizFinalAudit/2.0'],
    ]);
    curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $effectiveUrl = (string)curl_getinfo($ch, CURLINFO_EFFECTIVE_URL);
    $error = curl_errno($ch);
    curl_close($ch);
    return ['status' => $error === 0 ? $status : 0, 'effective_url' => $effectiveUrl];
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

$host = svre_value('DB_HOST', $root) ?: 'localhost';
$portRaw = svre_value('DB_PORT', $root);
$port = ctype_digit($portRaw) ? (int)$portRaw : 3306;
$name = svre_value(['DB_NAME', 'DB_DATABASE'], $root);
$user = svre_value(['DB_USER', 'DB_USERNAME'], $root);
$pass = svre_value(['DB_PASS', 'DB_PASSWORD'], $root);
if ($name === '' || $user === '' || strtolower($user) === 'root') {
    throw new RuntimeException('verified_database_configuration_missing');
}
$pdo = new PDO(
    sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $host, $port, $name),
    $user,
    $pass,
    [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::ATTR_TIMEOUT => 5,
    ]
);
$stmt = $pdo->prepare('SELECT order_status, olist_order_id FROM orders WHERE order_number = :order_number');
$stmt->execute([':order_number' => $orderNumber]);
$rows = $stmt->fetchAll() ?: [];
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

$version = svfa_http_json('https://shopvivaliz.com.br/api/health/version.php?final_order_audit=2');
$ordersHealth = svfa_http_json('https://shopvivaliz.com.br/api/orders/health.php?final_order_audit=2');
$olistHealth = svfa_http_json('https://shopvivaliz.com.br/api/olist/webhook-health.php?final_order_audit=2');
$lizHealth = svfa_http_json('https://shopvivaliz.com.br/api/liz-intelligent.php?health=1&final_order_audit=2');
$home = svfa_http_status('https://shopvivaliz.com.br/?final_order_audit=2', true);
$catalog = svfa_http_status('https://shopvivaliz.com.br/catalogo?final_order_audit=2', true);
$checkout = svfa_http_status('https://shopvivaliz.com.br/checkout?final_order_audit=2', true);
$webhookGet = svfa_http_status('https://shopvivaliz.com.br/api/webhook-mercadopago.php?final_order_audit=2');
$catalogParsed = parse_url($catalog['effective_url']);

clearstatcache(true, $path);
$afterHash = hash_file('sha256', $path);

$checks = [
    'exact_release_active' => ($version['json']['release_sha'] ?? '') === $expectedSha,
    'version_health_ok' => $version['status'] === 200 && ($version['json']['ok'] ?? false) === true,
    'orders_health_ok' => $ordersHealth['status'] === 200 && ($ordersHealth['json']['ok'] ?? false) === true,
    'olist_health_ok' => $olistHealth['status'] === 200 && ($olistHealth['json']['ok'] ?? false) === true,
    'liz_health_ok' => $lizHealth['status'] === 200 && ($lizHealth['json']['ok'] ?? false) === true,
    'home_http_200' => $home['status'] === 200,
    'catalog_canonical_http_200' => $catalog['status'] === 200
        && ($catalogParsed['scheme'] ?? '') === 'https'
        && ($catalogParsed['host'] ?? '') === 'shopvivaliz.com.br'
        && rtrim((string)($catalogParsed['path'] ?? ''), '/') === '/catalogo',
    'checkout_http_200' => $checkout['status'] === 200,
    'webhook_get_rejected' => $webhookGet['status'] === 405,
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
    'release_sha' => (string)($version['json']['release_sha'] ?? ''),
    'provider_status_class' => svmp_local_status($providerStatus),
    'order_file_count' => $orderFileCount,
    'payment_file_count' => $paymentFileCount,
    'database_order_count' => count($rows),
    'tiny_id_hash12' => $tinyId !== '' ? substr(hash('sha256', $tinyId), 0, 12) : '',
    'database_tiny_id_hash12' => $dbTinyId !== '' ? substr(hash('sha256', $dbTinyId), 0, 12) : '',
    'http_statuses' => [
        'version' => $version['status'],
        'orders' => $ordersHealth['status'],
        'olist' => $olistHealth['status'],
        'liz' => $lizHealth['status'],
        'home' => $home['status'],
        'catalog_final' => $catalog['status'],
        'checkout' => $checkout['status'],
        'webhook_get' => $webhookGet['status'],
    ],
    'checks' => $checks,
    'failures' => $failures,
];
echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . PHP_EOL;
exit($failures === [] ? 0 : 1);

<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/amazon-returns/Config.php';
require_once __DIR__ . '/../includes/amazon-returns/RemoteBridge.php';

function rbSame(mixed $expected, mixed $actual, string $message): void {
    if ($expected !== $actual) {
        throw new RuntimeException($message . "\nExpected: " . var_export($expected, true) . "\nActual: " . var_export($actual, true));
    }
}
function rbAssert(bool $condition, string $message): void {
    if (!$condition) throw new RuntimeException($message);
}

$config = new SvAmazonReturnsConfig([
    'AMAZON_RETURNS_ENABLED' => '1',
    'AMAZON_RETURNS_MODE' => 'production',
    'AMAZON_RETURNS_SAFE_T_WRITE' => '1',
    'SELLER_CENTRAL_BRIDGE_TOKEN' => 'server-secret',
]);
$ready = $config->readiness()['seller_central_bridge'];
rbSame(true, $ready['ready'], 'Polling token must satisfy Seller Central bridge readiness.');
rbSame('polling', $config->sellerCentralBridgeMode(), 'Token-only bridge must use polling mode.');

rbAssert(SvAmazonReturnsRemoteBridge::authorized('server-secret', 'Bearer server-secret'), 'Exact Bearer token must authorize.');
rbAssert(!SvAmazonReturnsRemoteBridge::authorized('server-secret', 'Bearer wrong'), 'Wrong Bearer token must fail.');
rbAssert(!SvAmazonReturnsRemoteBridge::authorized('', 'Bearer server-secret'), 'Missing server token must fail closed.');

$row = [
    'id' => 41,
    'case_id' => 77,
    'kind' => 'SAFE_T_SUBMIT',
    'idempotency_key' => str_repeat('a', 64),
    'attempt_count' => 1,
    'payload' => [
        'reason_code' => 'RNOTR',
        'reason_subcategory' => 'RNOTR-a',
        'narrative' => 'Item not returned after Amazon refund.',
    ],
];
$case = [
    'id' => 77,
    'amazon_order_id' => '702-1234567-7654321',
    'amazon_order_item_id' => 'item-1',
    'safe_t_id' => null,
    'support_case_id' => null,
    'quantity_refunded' => 1,
    'quantity_received' => 0,
];
$job = SvAmazonReturnsRemoteBridge::jobEnvelope($row, $case, ['SAFE_T_SUBMIT' => true]);
rbSame('SAFE_T_SUBMIT', $job['action'], 'Bridge job must preserve approved action.');
rbSame('702-1234567-7654321', $job['case']['order_id'], 'Bridge job must expose only required order identity.');
rbSame(true, $job['write_enabled'], 'Server write flag must travel with the job.');

$valid = SvAmazonReturnsRemoteBridge::validateResult([
    'status' => 'ACCEPTED',
    'submitted' => true,
    'external_id' => '98143-99485-9285859',
    'retry_safe' => true,
    'evidence' => ['snapshot_sha256' => str_repeat('b', 64)],
]);
rbSame('ACCEPTED', $valid['status'], 'Accepted bridge result must validate.');
rbSame('98143-99485-9285859', $valid['external_id'], 'Read-back ID must survive validation.');

$thrown = false;
try {
    SvAmazonReturnsRemoteBridge::validateResult([
        'status' => 'ACCEPTED',
        'submitted' => true,
        'external_id' => null,
    ]);
} catch (RuntimeException) {
    $thrown = true;
}
rbAssert($thrown, 'Submitted write without external read-back ID must be rejected.');

$thrown = false;
try {
    SvAmazonReturnsRemoteBridge::jobEnvelope(array_merge($row, ['kind' => 'DELETE_ANYTHING']), $case, []);
} catch (InvalidArgumentException) {
    $thrown = true;
}
rbAssert($thrown, 'Remote bridge must reject unapproved action kinds.');

echo "amazon-returns-remote-bridge-test: OK\n";

$endpoint = __DIR__ . '/../api/amazon-returns/bridge.php';
$windowsWorker = __DIR__ . '/../scripts/amazon-returns/seller-central-bridge-worker.mjs';
$windowsInstaller = __DIR__ . '/../scripts/install-amazon-returns-windows-bridge.ps1';
rbAssert(is_file($endpoint), 'Authenticated remote bridge endpoint must exist.');
rbAssert(is_file($windowsWorker), 'Persistent Windows bridge worker must exist.');
rbAssert(is_file($windowsInstaller), 'Windows bridge installer must exist.');
$endpointSource = (string)file_get_contents($endpoint);
rbAssert(str_contains($endpointSource, 'SELLER_CENTRAL_BRIDGE_TOKEN'), 'Bridge endpoint must require server-side token.');
rbAssert(!str_contains($endpointSource, "\$_GET['token']"), 'Bridge token must never be accepted from query string.');
$workerSource = (string)file_get_contents($windowsWorker);
rbAssert(str_contains($workerSource, 'bridge.token'), 'Windows worker must read token from protected file.');
rbAssert(str_contains($workerSource, '127.0.0.1:9225'), 'Windows worker must use local headless CDP only.');

$installerSource=(string)file_get_contents(__DIR__.'/../scripts/install-amazon-returns-windows-bridge.ps1');
rbAssert(!str_contains($installerSource,'New-ScheduledTaskTrigger -AtStartup,'),'Windows bridge installer must use valid trigger expressions without a trailing command comma.');
rbAssert(!str_contains($installerSource,'C:\\Users\\FRED'),'Windows bridge installer must not hardcode the Fred-Win user profile.');
rbAssert(str_contains($installerSource,'LOCALAPPDATA'),'Windows bridge installer must discover Opera from the current host profile.');
$qaWorkflow=(string)file_get_contents(__DIR__.'/../.github/workflows/shopvivaliz-qa.yml');
rbAssert(str_contains($qaWorkflow,'node --check scripts/amazon-returns/seller-central-bridge-worker.mjs'),'CI must syntax-check the persistent Seller Central bridge worker.');
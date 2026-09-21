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

rbSame(
    'Bearer server-secret',
    SvAmazonReturnsRemoteBridge::resolveAuthorizationHeader(['HTTP_AUTHORIZATION' => 'Bearer server-secret'], []),
    'Authorization must resolve from $_SERVER when present.'
);
rbSame(
    'Bearer server-secret',
    SvAmazonReturnsRemoteBridge::resolveAuthorizationHeader([], ['Authorization' => 'Bearer server-secret']),
    'Authorization must fall back to apache_request_headers() when $_SERVER lacks it (the real production bug: Apache mod_php never populated HTTP_AUTHORIZATION for this endpoint).'
);
rbSame(
    'Bearer server-secret',
    SvAmazonReturnsRemoteBridge::resolveAuthorizationHeader([], ['authorization' => 'Bearer server-secret']),
    'Apache header fallback must match case-insensitively.'
);
rbSame(
    'Bearer from-server',
    SvAmazonReturnsRemoteBridge::resolveAuthorizationHeader(['HTTP_AUTHORIZATION' => 'Bearer from-server'], ['Authorization' => 'Bearer from-apache']),
    'When $_SERVER already has Authorization, it takes precedence over the Apache fallback.'
);
rbSame(
    '',
    SvAmazonReturnsRemoteBridge::resolveAuthorizationHeader([], []),
    'Missing Authorization everywhere must resolve to an empty string, not an error.'
);

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

$readCase = array_replace($case, ['safe_t_id'=>'98143-99485-9285859']);
$readJob = SvAmazonReturnsRemoteBridge::jobEnvelope(array_replace($row, ['kind'=>'SAFE_T_READ']), $readCase, []);
rbSame('SAFE_T_READ', $readJob['action'], 'Read-only SAFE-T status job must be an approved bridge action.');
rbSame(false, $readJob['write_enabled'], 'SAFE-T status reads must not require or imply a write flag.');

$valid = SvAmazonReturnsRemoteBridge::validateResult([
    'status' => 'ACCEPTED',
    'submitted' => true,
    'external_id' => '98143-99485-9285859',
    'retry_safe' => true,
    'evidence' => ['snapshot_sha256' => str_repeat('b', 64)],
]);
rbSame('ACCEPTED', $valid['status'], 'Accepted bridge result must validate.');
rbSame('98143-99485-9285859', $valid['external_id'], 'Read-back ID must survive validation.');

$readValid = SvAmazonReturnsRemoteBridge::validateResult([
    'status'=>'ACCEPTED',
    'submitted'=>false,
    'external_id'=>'98143-99485-9285859',
    'retry_safe'=>true,
    'read'=>[
        'claim_status'=>'DENIED',
        'safe_t_id'=>'98143-99485-9285859',
        'order_id'=>'702-9582024-4340203',
        'denied_at'=>'2026-09-01T13:23:00-03:00',
        'appeal_deadline_at'=>'2026-09-08T13:23:00-03:00',
        'decision_text'=>'Negado porque a devolução foi considerada concluída.',
    ],
]);
rbSame('DENIED', $readValid['read']['claim_status'], 'SAFE-T status read must survive bridge validation.');
rbSame('2026-09-08 16:23:00', $readValid['read']['appeal_deadline_at'], 'Seller Central appeal deadline must normalize to UTC.');
rbAssert(preg_match('/^[a-f0-9]{64}$/', (string)$readValid['read']['decision_fingerprint']) === 1, 'Decision text must receive a deterministic fingerprint.');

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
$statusEndpoint = __DIR__ . '/../api/amazon-returns/status-bridge.php';
$retiredWorker = __DIR__ . '/../scripts/amazon-returns/seller-central-bridge-worker.mjs';
$retiredStatusWorker = __DIR__ . '/../scripts/amazon-returns/seller-central-safe-t-read-worker.mjs';
$statusParser = __DIR__ . '/../scripts/amazon-returns/safe-t-status-parser.mjs';
$retiredInstaller = __DIR__ . '/../scripts/install-amazon-returns-windows-bridge.ps1';
rbAssert(is_file($endpoint), 'Authenticated remote bridge endpoint must exist.');
rbAssert(is_file($statusEndpoint), 'Authenticated read-only SAFE-T status endpoint must exist.');
rbAssert(is_file($statusParser), 'SAFE-T Seller Central status parser must exist.');
$endpointSource = (string)file_get_contents($endpoint);
$statusEndpointSource = (string)file_get_contents($statusEndpoint);
rbAssert(str_contains($endpointSource, 'SELLER_CENTRAL_BRIDGE_TOKEN'), 'Bridge endpoint must require server-side token.');
rbAssert(str_contains($statusEndpointSource, 'SELLER_CENTRAL_BRIDGE_TOKEN'), 'Status bridge must require the same protected server-side token.');
rbAssert(str_contains($statusEndpointSource, "kind='SAFE_T_READ'"), 'Status bridge may claim SAFE_T_READ jobs only.');
rbAssert(str_contains($endpointSource, 'apache_request_headers'), 'Bridge endpoint must preserve Apache Authorization fallback.');
rbAssert(str_contains($statusEndpointSource, 'apache_request_headers'), 'Status bridge must preserve Apache Authorization fallback.');
rbAssert(!str_contains($endpointSource, "\$_GET['token']"), 'Bridge token must never be accepted from query string.');
rbAssert(!str_contains($statusEndpointSource, "\$_GET['token']"), 'Status bridge token must never be accepted from query string.');
foreach ([$retiredWorker, $retiredStatusWorker, $retiredInstaller] as $retired) {
    rbAssert(is_file($retired), 'Retired Windows browser bridge marker file must remain for fail-closed compatibility.');
    $source = (string)file_get_contents($retired);
    rbAssert(str_contains($source, 'WINDOWS_BROWSER_RETIRED_V1'), 'Retired Windows browser bridge must fail closed.');
    rbAssert(str_contains($source, 'WINDOWS_BROWSER_FORBIDDEN'), 'Retired Windows browser bridge must explain the VM-only policy.');
}
$qaWorkflow=(string)file_get_contents(__DIR__.'/../.github/workflows/shopvivaliz-qa.yml');
rbAssert(str_contains($qaWorkflow,'node --check scripts/amazon-returns/seller-central-bridge-worker.mjs'),'CI must syntax-check the retired compatibility stub.');
echo "amazon-returns-windows-browser-retirement: OK\n";

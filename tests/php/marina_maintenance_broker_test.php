<?php

declare(strict_types=1);

$brokerPath = __DIR__ . '/../../includes/marina-maintenance/broker-client.php';
if (!is_file($brokerPath)) {
    fwrite(STDERR, "RED: Marina maintenance broker adapter is not implemented yet\n");
    exit(1);
}
require_once $brokerPath;

function marina_broker_config(array $overrides = []): array
{
    return array_replace([
        'broker_url' => 'https://broker.maintenance.internal',
        'private_route' => 'vcn-marina-private',
        'audience' => 'marina-maintenance-broker',
        'transport_identity' => 'spiffe://shopvivaliz/maintenance/broker-v1',
        'ca_file' => '/official/ca.pem', 'client_cert' => '/official/client.crt', 'client_key' => '/official/client.key',
        'spki_pin' => 'sha256//' . str_repeat('A', 43),
    ], $overrides);
}

function marina_broker_ticket(): array
{
    return [
        'ticket_version' => 2, 'operation' => 'retire_origin_abandoned_cart_scheduler', 'operator_id' => 'admin:42',
        'ticket_id' => 'ticket-abc-123', 'nonce' => 'nonce-abc-123', 'issued_at' => time(), 'expires_at' => time() + 120,
        'audience' => 'marina-maintenance-broker', 'authorization_request_id' => 'request-abc-123', 'requested_by' => 'admin:42',
        'preflight_id' => 'preflight-abc-123', 'preflight_digest' => str_repeat('a', 64),
        'scope' => ['source_host' => 'shopvivaliz-free-a1', 'source_unit' => 'shopvivaliz-abandoned-cart-recovery.timer', 'target_host' => 'marina'],
    ];
}

function marina_broker_assert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$missing = new SvMarinaMaintenanceBrokerClient(null, []);
$missingStatus = $missing->status();
marina_broker_assert(($missingStatus['state'] ?? '') === 'NAO_APTO', 'missing broker must remain NAO_APTO');
marina_broker_assert(($missingStatus['code'] ?? '') === 'broker_not_provisioned', 'missing broker must expose provisioning code');

$public = new SvMarinaMaintenanceBrokerClient(null, marina_broker_config(['broker_url' => 'https://broker.public.example', 'private_route' => 'public-catalog']));
$publicStatus = $public->status();
marina_broker_assert(($publicStatus['state'] ?? '') === 'NAO_APTO', 'public route must remain blocked');
marina_broker_assert(($publicStatus['code'] ?? '') === 'private_route_required', 'public route must expose private-route refusal');

$transportCalls = [];
$transport = static function (string $method, string $path, array $payload) use (&$transportCalls): array {
    $transportCalls[] = [$method, $path, $payload];
    return ['state' => 'SUCCEEDED_RECONCILED', 'operation_id' => 'op-1'];
};
$configured = new SvMarinaMaintenanceBrokerClient($transport, [
    ...marina_broker_config(),
]);
$context = [
    'authorization_request_id' => 'request-abc-123',
    'requested_by' => 'admin:42',
    'mfa_verified_at' => time(),
];
$success = $configured->authorize(marina_broker_ticket(), base64_encode('detached-signature'), $context, ['preflight_id' => 'preflight-abc-123', 'preflight_digest' => str_repeat('a', 64)]);
marina_broker_assert(($success['state'] ?? '') === 'SUCCEEDED_RECONCILED', 'configured transport response must be preserved');
marina_broker_assert(count($transportCalls) === 1, 'configured broker should receive one approval request');
marina_broker_assert(($transportCalls[0][0] ?? '') === 'POST', 'approval must use POST');
marina_broker_assert(($transportCalls[0][1] ?? '') === '/v1/maintenance/authorize', 'authorization path must be fixed');
marina_broker_assert(!array_key_exists('private_key', $transportCalls[0][2] ?? []), 'private key must never reach transport');

$malformed = new SvMarinaMaintenanceBrokerClient(
    static fn(string $method, string $path, array $payload): array => ['not_state' => true],
    [
        ...marina_broker_config(),
    ]
);
$malformedResult = $malformed->status();
marina_broker_assert(($malformedResult['state'] ?? '') === 'UNKNOWN', 'malformed broker response must be unknown');
marina_broker_assert(($malformedResult['code'] ?? '') === 'broker_response_invalid', 'malformed broker response must be explicit');

$refused = new SvMarinaMaintenanceBrokerClient(
    static fn(string $method, string $path, array $payload): array => ['state' => 'REFUSED', 'code' => 'operator_not_trusted'],
    [
        ...marina_broker_config(),
    ]
);
$refusedResult = $refused->authorize(marina_broker_ticket(), base64_encode('detached-signature'), $context, ['preflight_id' => 'preflight-abc-123', 'preflight_digest' => str_repeat('a', 64)]);
marina_broker_assert(($refusedResult['state'] ?? '') === 'REFUSED', 'broker refusal must remain refusal');

$publicHostname = new SvMarinaMaintenanceBrokerClient(static fn() => ['state' => 'READY_FOR_APPROVAL'], marina_broker_config(['broker_url' => 'https://broker.public.example']));
marina_broker_assert(($publicHostname->status()['code'] ?? '') === 'broker_private_hostname_required', 'public DNS must fail before transport');
$missingIdentity = new SvMarinaMaintenanceBrokerClient(static fn() => ['state' => 'READY_FOR_APPROVAL'], marina_broker_config(['transport_identity' => '']));
marina_broker_assert(($missingIdentity->status()['code'] ?? '') === 'broker_not_provisioned', 'missing official transport identity must fail closed');
$redacted = new SvMarinaMaintenanceBrokerClient(static fn() => ['state' => 'APPROVAL_REQUIRED', 'preflight' => ['ticket' => 'must-not-leak', 'lease' => 'private']], marina_broker_config());
$redactedResult = $redacted->status();
marina_broker_assert(!array_key_exists('preflight', $redactedResult), 'arbitrary nested preflight must never reach UI projection');

fwrite(STDOUT, "marina_maintenance_broker_test: OK\n");

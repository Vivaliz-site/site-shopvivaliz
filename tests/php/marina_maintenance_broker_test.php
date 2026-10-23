<?php

declare(strict_types=1);

$brokerPath = __DIR__ . '/../../includes/marina-maintenance/broker-client.php';
if (!is_file($brokerPath)) {
    fwrite(STDERR, "RED: Marina maintenance broker adapter is not implemented yet\n");
    exit(1);
}
require_once $brokerPath;

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

$public = new SvMarinaMaintenanceBrokerClient(null, [
    'broker_url' => 'https://137.131.173.240',
    'private_route' => 'public-catalog',
    'audience' => 'marina-maintenance',
]);
$publicStatus = $public->status();
marina_broker_assert(($publicStatus['state'] ?? '') === 'NAO_APTO', 'public route must remain blocked');
marina_broker_assert(($publicStatus['code'] ?? '') === 'private_route_required', 'public route must expose private-route refusal');

$transportCalls = [];
$transport = static function (string $method, string $path, array $payload) use (&$transportCalls): array {
    $transportCalls[] = [$method, $path, $payload];
    return ['state' => 'SUCCEEDED_RECONCILED', 'operation_id' => 'op-1'];
};
$configured = new SvMarinaMaintenanceBrokerClient($transport, [
    'broker_url' => 'https://marina-private.example.invalid',
    'private_route' => 'vcn-marina-private',
    'audience' => 'marina-maintenance',
]);
$success = $configured->approve([
    'ticket_version' => 1,
    'operation' => 'retire_origin_abandoned_cart_scheduler',
    'operator_id' => 'VERIFIED_OPERATOR',
    'ticket_id' => 'ticket-abc-123',
    'nonce' => 'nonce-abc-123',
    'issued_at' => time(),
    'expires_at' => time() + 120,
    'scope' => [
        'source_host' => 'shopvivaliz-free-a1',
        'source_unit' => 'shopvivaliz-abandoned-cart-recovery.timer',
        'target_host' => 'marina',
    ],
], base64_encode('detached-signature'));
marina_broker_assert(($success['state'] ?? '') === 'SUCCEEDED_RECONCILED', 'configured transport response must be preserved');
marina_broker_assert(count($transportCalls) === 1, 'configured broker should receive one approval request');
marina_broker_assert(($transportCalls[0][0] ?? '') === 'POST', 'approval must use POST');
marina_broker_assert(($transportCalls[0][1] ?? '') === '/v1/maintenance/approve', 'approval path must be fixed');
marina_broker_assert(!array_key_exists('private_key', $transportCalls[0][2] ?? []), 'private key must never reach transport');

$malformed = new SvMarinaMaintenanceBrokerClient(
    static fn(string $method, string $path, array $payload): array => ['not_state' => true],
    [
        'broker_url' => 'https://marina-private.example.invalid',
        'private_route' => 'vcn-marina-private',
        'audience' => 'marina-maintenance',
    ]
);
$malformedResult = $malformed->status();
marina_broker_assert(($malformedResult['state'] ?? '') === 'UNKNOWN', 'malformed broker response must be unknown');
marina_broker_assert(($malformedResult['code'] ?? '') === 'broker_response_invalid', 'malformed broker response must be explicit');

$refused = new SvMarinaMaintenanceBrokerClient(
    static fn(string $method, string $path, array $payload): array => ['state' => 'REFUSED', 'code' => 'operator_not_trusted'],
    [
        'broker_url' => 'https://marina-private.example.invalid',
        'private_route' => 'vcn-marina-private',
        'audience' => 'marina-maintenance',
    ]
);
$refusedResult = $refused->approve([
    'ticket_version' => 1,
    'operation' => 'retire_origin_abandoned_cart_scheduler',
    'operator_id' => 'VERIFIED_OPERATOR',
    'ticket_id' => 'ticket-abc-123',
    'nonce' => 'nonce-abc-123',
    'issued_at' => time(),
    'expires_at' => time() + 120,
    'scope' => [
        'source_host' => 'shopvivaliz-free-a1',
        'source_unit' => 'shopvivaliz-abandoned-cart-recovery.timer',
        'target_host' => 'marina',
    ],
], base64_encode('detached-signature'));
marina_broker_assert(($refusedResult['state'] ?? '') === 'REFUSED', 'broker refusal must remain refusal');

fwrite(STDOUT, "marina_maintenance_broker_test: OK\n");

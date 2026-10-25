<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
require_once $root . '/includes/marina-maintenance/authorization-service.php';

function marina_one_click_assert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

function marina_one_click_ticket(array $overrides = []): array
{
    return array_replace_recursive([
        'ticket_version' => 2,
        'operation' => 'retire_origin_abandoned_cart_scheduler',
        'operator_id' => 'admin:42',
        'ticket_id' => 'ticket-one-click-123',
        'nonce' => 'nonce-one-click-123',
        'issued_at' => time(),
        'expires_at' => time() + 120,
        'audience' => 'marina-maintenance-broker',
        'authorization_request_id' => 'request-one-click-123',
        'requested_by' => 'admin:42',
        'preflight_id' => 'preview-123',
        'preflight_digest' => str_repeat('a', 64),
        'scope' => [
            'source_host' => 'shopvivaliz-free-a1',
            'source_unit' => 'shopvivaliz-abandoned-cart-recovery.timer',
            'target_host' => 'marina',
        ],
    ], $overrides);
}

$config = [
    'broker_url' => 'https://broker.maintenance.internal',
    'private_route' => 'vcn-marina-private',
    'audience' => 'marina-maintenance-broker',
    'transport_identity' => 'spiffe://shopvivaliz/maintenance/broker-v1',
    'ca_file' => '/official/ca.pem', 'client_cert' => '/official/client.crt', 'client_key' => '/official/client.key', 'spki_pin' => 'sha256//' . str_repeat('A', 43),
];
$signerConfig = [
    'signer_url' => 'https://signer.maintenance.internal',
    'private_route' => 'vcn-marina-private',
    'audience' => 'marina-maintenance-signer',
    'transport_identity' => 'spiffe://shopvivaliz/maintenance/signer-v1',
    'ca_file' => '/official/ca.pem', 'client_cert' => '/official/client.crt', 'client_key' => '/official/client.key', 'spki_pin' => 'sha256//' . str_repeat('A', 43),
];
$context = [
    'authorization_request_id' => 'request-one-click-123',
    'requested_by' => 'admin:42',
    'mfa_verified_at' => time(),
];

$authCalls = 0;
$authGuard = new SvMarinaMaintenanceBrokerClient(static function () use (&$authCalls): array {
    $authCalls++;
    return ['state' => 'APPROVAL_REQUIRED', 'preflight_id' => 'should-not-run'];
}, $config);
marina_one_click_assert(($authGuard->preflight([])['code'] ?? '') === 'authorization_context_invalid' && $authCalls === 0, 'missing authenticated context must be refused before broker access');
$staleContext = $context;
$staleContext['mfa_verified_at'] = time() - 901;
marina_one_click_assert(($authGuard->preflight($staleContext)['code'] ?? '') === 'reauth_required' && $authCalls === 0, 'stale MFA must be refused before broker access');

$missingSigner = new SvMarinaMaintenanceSignerClient(null, []);
marina_one_click_assert(($missingSigner->status()['code'] ?? '') === 'signer_not_provisioned', 'missing signer must fail closed');

$noBrokerCalls = 0;
$notReadyBroker = new SvMarinaMaintenanceBrokerClient(static function () use (&$noBrokerCalls): array { $noBrokerCalls++; return ['state' => 'APPROVAL_REQUIRED']; }, $config);
$notReadySigner = new SvMarinaMaintenanceSignerClient(static fn() => ['state' => 'NAO_APTO', 'code' => 'signer_not_provisioned'], $signerConfig);
$notReady = (new SvMarinaMaintenanceAuthorizationService($notReadyBroker, $notReadySigner))->authorize($context);
marina_one_click_assert(($notReady['code'] ?? '') === 'signer_not_provisioned' && $noBrokerCalls === 0, 'server must refuse before preflight when signer is not READY');
$notReadyStatus = (new SvMarinaMaintenanceAuthorizationService($notReadyBroker, $notReadySigner))->readiness();
marina_one_click_assert(($notReadyStatus['state'] ?? '') === 'NAO_APTO', 'readiness must fail closed when signer is not READY');
marina_one_click_assert(($notReadyStatus['ok'] ?? true) === false, 'readiness must not report usable when signer is not READY');

$calls = [];
$broker = new SvMarinaMaintenanceBrokerClient(static function (string $method, string $path, array $payload) use (&$calls): array {
    $calls[] = [$method, $path, $payload];
    return match ($path) {
        '/v1/maintenance/preflight' => ['state' => 'APPROVAL_REQUIRED', 'preflight_id' => 'preview-123', 'preflight_digest' => str_repeat('a', 64), 'freshness' => 'fresh'],
        '/v1/maintenance/authorize' => ['state' => 'SUCCEEDED_RECONCILED', 'operation_id' => 'operation-123', 'audit_id' => 'audit-123'],
        default => ['state' => 'UNKNOWN', 'code' => 'unexpected_path'],
    };
}, $config);
$signer = new SvMarinaMaintenanceSignerClient(static function (string $method, string $path, array $payload): array {
    return $path === '/v1/maintenance/signer/status'
        ? ['state' => 'READY']
        : ['state' => 'TICKET_ISSUED', 'ticket' => marina_one_click_ticket(), 'signature_b64' => base64_encode('independent-signature')];
}, $signerConfig);
$service = new SvMarinaMaintenanceAuthorizationService($broker, $signer);
$success = $service->authorize($context);
marina_one_click_assert(($success['state'] ?? '') === 'SUCCEEDED_RECONCILED', 'one click must forward only internally-issued authority to broker');
marina_one_click_assert(count($calls) === 2 && $calls[0][1] === '/v1/maintenance/preflight' && $calls[1][1] === '/v1/maintenance/authorize', 'preflight must precede authorization exactly once');
marina_one_click_assert(!array_key_exists('private_key', $calls[1][2] ?? []), 'private key must never reach broker payload');

$expiredBrokerCalls = 0;
$expiredBroker = new SvMarinaMaintenanceBrokerClient(static function (string $method, string $path) use (&$expiredBrokerCalls): array {
    $expiredBrokerCalls++;
    return $path === '/v1/maintenance/preflight' ? ['state' => 'APPROVAL_REQUIRED', 'preflight_id' => 'preview-expired', 'preflight_digest' => str_repeat('a', 64)] : ['state' => 'UNKNOWN'];
}, $config);
$expiredSigner = new SvMarinaMaintenanceSignerClient(static fn(string $method) => $method === 'GET' ? ['state' => 'READY'] : ['state' => 'TICKET_ISSUED', 'ticket' => marina_one_click_ticket(['expires_at' => time() - 1, 'preflight_id' => 'preview-expired']), 'signature_b64' => base64_encode('signature')], $signerConfig);
$expired = (new SvMarinaMaintenanceAuthorizationService($expiredBroker, $expiredSigner))->authorize($context);
marina_one_click_assert(($expired['code'] ?? '') === 'ticket_expired' && $expiredBrokerCalls === 1, 'expired signer ticket must not reach authorization');

foreach (['source_stopped', 'cron_hash_drift', 'operation_in_progress'] as $denial) {
    $signerCalls = 0;
    $deniedBroker = new SvMarinaMaintenanceBrokerClient(static fn() => ['state' => 'REFUSED', 'code' => $denial], $config);
    $countingSigner = new SvMarinaMaintenanceSignerClient(static function (string $method) use (&$signerCalls): array { if ($method === 'POST') { $signerCalls++; } return $method === 'GET' ? ['state' => 'READY'] : ['state' => 'TICKET_ISSUED']; }, $signerConfig);
    $result = (new SvMarinaMaintenanceAuthorizationService($deniedBroker, $countingSigner))->authorize($context);
    marina_one_click_assert(($result['code'] ?? '') === $denial && $signerCalls === 0, "{$denial} must deny before signer issuance");
}

$replayBroker = new SvMarinaMaintenanceBrokerClient(static function (string $method, string $path): array {
    return $path === '/v1/maintenance/preflight'
        ? ['state' => 'APPROVAL_REQUIRED', 'preflight_id' => 'preview-123', 'preflight_digest' => str_repeat('a', 64)]
        : ['state' => 'REFUSED', 'code' => 'nonce_replayed'];
}, $config);
$replay = (new SvMarinaMaintenanceAuthorizationService($replayBroker, $signer))->authorize($context);
marina_one_click_assert(($replay['code'] ?? '') === 'nonce_replayed', 'broker replay ledger refusal must be preserved');

fwrite(STDOUT, "marina_maintenance_one_click_test: OK\n");

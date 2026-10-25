<?php

declare(strict_types=1);

$contractPath = __DIR__ . '/../../includes/marina-maintenance/contract.php';
if (!is_file($contractPath)) {
    fwrite(STDERR, "RED: Marina maintenance contract is not implemented yet\n");
    exit(1);
}
require_once $contractPath;

function marina_contract_assert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

function marina_valid_ticket(array $overrides = []): array
{
    return array_replace_recursive([
        'ticket_version' => 1,
        'operation' => 'retire_origin_abandoned_cart_scheduler',
        'operator_id' => 'VERIFIED_OPERATOR',
        'ticket_id' => 'ticket-abc-123',
        'nonce' => 'nonce-abc-123',
        'issued_at' => 1000,
        'expires_at' => 1120,
        'scope' => [
            'source_host' => 'shopvivaliz-free-a1',
            'source_unit' => 'shopvivaliz-abandoned-cart-recovery.timer',
            'target_host' => 'marina',
        ],
    ], $overrides);
}

marina_contract_assert(
    sv_marina_maintenance_operation() === 'retire_origin_abandoned_cart_scheduler',
    'operation must remain fixed'
);

$left = marina_valid_ticket([
    'scope' => [
        'target_host' => 'marina',
        'source_unit' => 'shopvivaliz-abandoned-cart-recovery.timer',
        'source_host' => 'shopvivaliz-free-a1',
    ],
]);
$right = marina_valid_ticket();
marina_contract_assert(
    sv_marina_canonical_ticket($left) === sv_marina_canonical_ticket($right),
    'canonical ticket must sort object keys recursively'
);

$valid = sv_marina_validate_ticket(marina_valid_ticket(), 1000);
marina_contract_assert(($valid['ok'] ?? false) === true, 'valid ticket must pass');
marina_contract_assert(($valid['code'] ?? '') === 'ok', 'valid ticket must return ok code');

$tooLong = sv_marina_validate_ticket(marina_valid_ticket(['expires_at' => 1181]), 1000);
marina_contract_assert(($tooLong['ok'] ?? true) === false, 'ticket longer than 180 seconds must fail');
marina_contract_assert(($tooLong['code'] ?? '') === 'ticket_ttl_exceeded', 'long ticket must expose bounded TTL code');

$expired = sv_marina_validate_ticket(marina_valid_ticket(['expires_at' => 999]), 1000);
marina_contract_assert(($expired['ok'] ?? true) === false, 'expired ticket must fail');
marina_contract_assert(($expired['code'] ?? '') === 'ticket_expired', 'expired ticket must expose expiry code');

foreach (['private_key', 'host', 'command', 'bearer_token', 'client_secret'] as $forbidden) {
    $payload = marina_valid_ticket([$forbidden => 'must-not-be-accepted']);
    $result = sv_marina_validate_ticket($payload, 1000);
    marina_contract_assert(($result['ok'] ?? true) === false, "forbidden field {$forbidden} must fail");
    marina_contract_assert(($result['code'] ?? '') === 'unknown_field', "forbidden field {$forbidden} must be rejected as unknown");
}

$projection = sv_marina_audit_projection([
    'operation_id' => 'op-123',
    'signature' => 'raw-signature',
    'private_key' => 'raw-private-key',
    'bearer_token' => 'raw-bearer-token',
    'state' => 'REFUSED',
]);
marina_contract_assert(($projection['operation_id'] ?? '') === 'op-123', 'audit projection keeps safe identifiers');
marina_contract_assert(($projection['signature'] ?? '') === '[REDACTED]', 'audit projection redacts signatures');
marina_contract_assert(($projection['private_key'] ?? '') === '[REDACTED]', 'audit projection redacts private keys');
marina_contract_assert(($projection['bearer_token'] ?? '') === '[REDACTED]', 'audit projection redacts bearer tokens');

fwrite(STDOUT, "marina_maintenance_contract_test: OK\n");

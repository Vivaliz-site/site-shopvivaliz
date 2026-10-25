<?php

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$endpointRoot = $root . '/api/admin';
$brokerSource = (string)file_get_contents($root . '/includes/marina-maintenance/broker-client.php');
$files = [
    'status' => $endpointRoot . '/marina-maintenance-status.php',
    'preview' => $endpointRoot . '/marina-maintenance-preview.php',
    'approve' => $endpointRoot . '/marina-maintenance-approve.php',
    'operation' => $endpointRoot . '/marina-maintenance-operation.php',
];

function marina_endpoint_assert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

foreach ($files as $name => $file) {
    marina_endpoint_assert(is_file($file), "{$name} endpoint must exist");
    $source = (string)file_get_contents($file);
    $sharedSource = (string)file_get_contents($root . '/includes/marina-maintenance/endpoint.php');
    marina_endpoint_assert(str_contains($source, "includes/admin-guard.php"), "{$name} endpoint must require admin guard");
    marina_endpoint_assert(str_contains($source, "includes/csrf.php"), "{$name} endpoint must require CSRF helper");
    marina_endpoint_assert(str_contains($source, "endpoint.php") && str_contains($sharedSource, "broker-client.php"), "{$name} endpoint must use the broker client");
    marina_endpoint_assert(!preg_match('/(?:ssh|shell_exec|passthru|proc_open|system\s*\(|runtime_lock|crontab|systemctl)/i', $source), "{$name} endpoint must not contain direct host mutation");
}

$approve = (string)file_get_contents($files['approve']);
marina_endpoint_assert(str_contains($approve, 'sv_marina_require_recent_reauth') && str_contains($sharedSource, 'reauth_required'), 'approval must require recent re-authentication');
marina_endpoint_assert(!str_contains($approve, 'signature_b64') && !str_contains($approve, "['ticket']"), 'approval must not accept browser-supplied ticket or signature');
marina_endpoint_assert(str_contains($approve, 'sv_marina_authorization_context'), 'approval must derive one-click context from the authenticated session');
marina_endpoint_assert(str_contains($approve, 'sv_marina_authorization_service'), 'approval must use the internal signer/broker orchestration service');
marina_endpoint_assert(str_contains($brokerSource, 'operation_id') && str_contains($approve, 'sv_marina_json'), 'approval response must expose operation identifier when available');
marina_endpoint_assert(is_file($root . '/includes/marina-maintenance/signer-client.php'), 'independent signer client must exist');
marina_endpoint_assert(is_file($root . '/includes/marina-maintenance/authorization-service.php'), 'one-click authorization service must exist');
marina_endpoint_assert(!str_contains($sharedSource, 'admin_recent_reauth_at') && !str_contains($sharedSource, "['recent_reauth_at']"), 'generic reauthentication must never satisfy MFA freshness');
marina_endpoint_assert(str_contains($sharedSource, "['mfa_verified_at']"), 'only official MFA session assertion may satisfy freshness');

$preview = (string)file_get_contents($files['preview']);
marina_endpoint_assert(str_contains($preview, 'sv_marina_require_recent_reauth'), 'preview must require recent re-authentication');

$status = (string)file_get_contents($files['status']);
marina_endpoint_assert(str_contains($status, "'GET'"), 'status must be read-only');

$operation = (string)file_get_contents($files['operation']);
marina_endpoint_assert(str_contains($operation, 'operation_id'), 'operation endpoint must validate operation id');
marina_endpoint_assert(str_contains($operation, "'GET'"), 'operation endpoint must reject non-GET requests');
marina_endpoint_assert(str_contains($operation, 'sv_marina_authorization_context') && str_contains($operation, 'sv_marina_require_recent_reauth'), 'operation lookup must bind to a fresh authenticated requester');

fwrite(STDOUT, "marina_maintenance_endpoint_test: OK\n");

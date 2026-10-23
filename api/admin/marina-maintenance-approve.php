<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/admin-guard.php';
require_once __DIR__ . '/../../includes/csrf.php';
require_once __DIR__ . '/../../includes/marina-maintenance/endpoint.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    sv_marina_json(['ok' => false, 'state' => 'REFUSED', 'code' => 'method_not_allowed'], 405);
}
sv_marina_require_csrf('marina-maintenance');
sv_marina_require_recent_reauth();

$payload = sv_marina_request_payload();
$ticket = is_array($payload['ticket'] ?? null) ? $payload['ticket'] : [];
$signature = (string)($payload['signature_b64'] ?? '');
$validation = sv_marina_validate_ticket($ticket, time());
if (($validation['ok'] ?? false) !== true) {
    sv_marina_json([
        'ok' => false,
        'state' => 'REFUSED',
        'code' => (string)($validation['code'] ?? 'ticket_invalid'),
    ], 422);
}
if (!sv_marina_signature_is_valid_shape($signature)) {
    sv_marina_json(['ok' => false, 'state' => 'REFUSED', 'code' => 'signature_invalid'], 422);
}

$result = sv_marina_broker()->approve($validation['ticket'], $signature);
sv_marina_json($result, ($result['state'] ?? '') === 'REFUSED' ? 422 : 200);


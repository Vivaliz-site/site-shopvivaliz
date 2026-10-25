<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/admin-guard.php';
require_once __DIR__ . '/../../includes/csrf.php';
require_once __DIR__ . '/../../includes/marina-maintenance/endpoint.php';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    sv_marina_json(['ok' => false, 'state' => 'REFUSED', 'code' => 'method_not_allowed'], 405);
}
sv_marina_require_csrf('marina-maintenance');
$mfaVerifiedAt = sv_marina_require_recent_reauth();
sv_marina_json(sv_marina_authorization_service()->preview(sv_marina_authorization_context($mfaVerifiedAt)));


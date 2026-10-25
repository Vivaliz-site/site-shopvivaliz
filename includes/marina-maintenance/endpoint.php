<?php

declare(strict_types=1);

require_once __DIR__ . '/contract.php';
require_once __DIR__ . '/broker-client.php';
require_once __DIR__ . '/signer-client.php';
require_once __DIR__ . '/authorization-service.php';
require_once dirname(__DIR__) . '/csrf.php';

function sv_marina_json(array $payload, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function sv_marina_request_payload(): array
{
    $raw = file_get_contents('php://input');
    if (!is_string($raw) || trim($raw) === '') {
        return is_array($_POST ?? []) ? $_POST : [];
    }
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
}

function sv_marina_require_csrf(string $scope): void
{
    $provided = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($_POST['csrf_token'] ?? null);
    if (!sv_csrf_valid($scope, $provided)) {
        sv_marina_json(['ok' => false, 'state' => 'REFUSED', 'code' => 'csrf_invalid'], 403);
    }
}

function sv_marina_recent_reauth_at(int $maxAge = SV_MARINA_MAINTENANCE_MFA_MAX_AGE): ?int
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
    $now = time();
    // A generic password-only reauthentication is not MFA. The central authentication
    // flow must set this server-side value only after an official MFA assertion.
    $timestamp = (int)($_SESSION['mfa_verified_at'] ?? 0);
    if ($timestamp > 0 && $timestamp <= $now && ($now - $timestamp) <= $maxAge) {
        return $timestamp;
    }
    return null;
}

function sv_marina_require_recent_reauth(int $maxAge = SV_MARINA_MAINTENANCE_MFA_MAX_AGE): int
{
    $timestamp = sv_marina_recent_reauth_at($maxAge);
    if ($timestamp !== null) {
        return $timestamp;
    }
    sv_marina_json([
        'ok' => false,
        'state' => 'REFUSED',
        'code' => 'reauth_required',
        'message' => 'Reautenticacao/MFA recente exigida para esta operacao.',
    ], 428);
}

function sv_marina_broker(): SvMarinaMaintenanceBrokerClient
{
    return new SvMarinaMaintenanceBrokerClient();
}

function sv_marina_signer(): SvMarinaMaintenanceSignerClient
{
    return new SvMarinaMaintenanceSignerClient();
}

function sv_marina_authorization_service(): SvMarinaMaintenanceAuthorizationService
{
    return new SvMarinaMaintenanceAuthorizationService(sv_marina_broker(), sv_marina_signer());
}

/** @return array<string, mixed> */
function sv_marina_authorization_context(int $mfaVerifiedAt): array
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
    $userId = (int)($_SESSION['user_id'] ?? 0);
    if ($userId < 1 || empty($_SESSION['is_admin'])) {
        sv_marina_json(['ok' => false, 'state' => 'REFUSED', 'code' => 'maintenance_role_required'], 403);
    }
    $existing = $_SESSION['marina_maintenance_authorization_request'] ?? null;
    $requestedBy = 'admin:' . $userId;
    if (!is_array($existing) || !is_string($existing['id'] ?? null) || !is_int($existing['created_at'] ?? null)
        || ($existing['requested_by'] ?? null) !== $requestedBy
        || (time() - $existing['created_at']) > SV_MARINA_MAINTENANCE_MAX_TTL) {
        $_SESSION['marina_maintenance_authorization_request'] = [
            'id' => 'request-' . bin2hex(random_bytes(16)),
            'created_at' => time(),
            'requested_by' => $requestedBy,
        ];
    }
    return [
        'authorization_request_id' => (string)$_SESSION['marina_maintenance_authorization_request']['id'],
        'requested_by' => $requestedBy,
        'mfa_verified_at' => $mfaVerifiedAt,
    ];
}


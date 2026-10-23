<?php

declare(strict_types=1);

require_once __DIR__ . '/contract.php';
require_once __DIR__ . '/broker-client.php';
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

function sv_marina_require_recent_reauth(int $maxAge = 900): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }
    $now = time();
    $timestamps = [
        (int)($_SESSION['admin_recent_reauth_at'] ?? 0),
        (int)($_SESSION['mfa_verified_at'] ?? 0),
        (int)($_SESSION['recent_reauth_at'] ?? 0),
    ];
    foreach ($timestamps as $timestamp) {
        if ($timestamp > 0 && $timestamp <= $now && ($now - $timestamp) <= $maxAge) {
            return;
        }
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


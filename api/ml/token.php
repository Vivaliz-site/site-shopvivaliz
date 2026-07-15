<?php
declare(strict_types=1);
require_once __DIR__ . '/client.php';

header_remove('X-Powered-By');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$action = trim($_GET['action'] ?? '');
$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

// POST /api/ml/token/refresh  ou  GET /api/ml/token/status
if ($action === 'refresh' || $method === 'POST') {
    try {
        $tokens = ml_refresh_if_needed();
        echo json_encode([
            'ok'            => true,
            'user_id'       => $tokens['user_id'] ?? null,
            'expires_at_ms' => $tokens['expires_at_ms'] ?? null,
        ], JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['error' => 'refresh_failed', 'details' => $e->getMessage()]);
    }
    exit;
}

// GET /api/ml/token/status
try {
    $tokens  = ml_read_tokens();
    $now_ms  = (int)(microtime(true) * 1000);
    $exp_ms  = (int)($tokens['expires_at_ms'] ?? 0);
    echo json_encode([
        'connected'         => true,
        'user_id'           => $tokens['user_id'] ?? null,
        'expires_at_ms'     => $exp_ms ?: null,
        'expires_in_seconds'=> $exp_ms ? max(0, (int)(($exp_ms - $now_ms) / 1000)) : null,
        'has_refresh_token' => !empty($tokens['refresh_token']),
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(404);
    echo json_encode(['connected' => false, 'error' => $e->getMessage()]);
}

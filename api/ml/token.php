<?php
declare(strict_types=1);
require_once __DIR__ . '/client.php';

header_remove('X-Powered-By');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

// Rodada 5 (2026-08-19): este endpoint expunha o status de conexao ML
// (user_id, has_refresh_token) publicamente, e o modo 'refresh' forcava
// renovacao do token OAuth para QUALQUER POST anonimo. O ML rotaciona
// refresh token a cada uso (single-use) -- um loop de POSTs anonimos era um
// caminho plausivel pra derrubar a integracao inteira sem credencial
// nenhuma. Ver R5-1 no relatorio da Rodada 5.
require_once dirname(__DIR__, 2) . '/config/require-agent-key.php';
sv_require_agent_key();

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
        error_log('[ml_token_refresh] ' . $e->getMessage());
        echo json_encode(['error' => 'refresh_failed']);
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
    error_log('[ml_token_status] ' . $e->getMessage());
    echo json_encode(['connected' => false, 'error' => 'no_tokens']);
}

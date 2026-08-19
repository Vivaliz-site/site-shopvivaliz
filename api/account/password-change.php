<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once dirname(__DIR__, 2) . '/includes/account-chrome.php';
require_once dirname(__DIR__, 2) . '/includes/pdo-database.php';
require_once dirname(__DIR__, 2) . '/includes/csrf.php';

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'method_not_allowed']);
    exit;
}

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'not_authenticated']);
    exit;
}

$body = json_decode(file_get_contents('php://input') ?: '', true);
$body = is_array($body) ? $body : [];

if (!sv_csrf_valid('account-actions', $body['csrf_token'] ?? null)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'invalid_csrf']);
    exit;
}

$currentPassword = (string)($body['current_password'] ?? '');
$newPassword = (string)($body['new_password'] ?? '');

if (strlen($newPassword) < 8) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'weak_password', 'message' => 'A nova senha deve ter ao menos 8 caracteres.']);
    exit;
}

try {
    $pdo = sv_pdo();
    $userId = (int)$_SESSION['user_id'];

    $stmt = $pdo->prepare('SELECT password_hash FROM users WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $userId]);
    $user = $stmt->fetch();

    if (!$user || !password_verify($currentPassword, (string)$user['password_hash'])) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'wrong_current_password']);
        exit;
    }

    $newHash = password_hash($newPassword, PASSWORD_BCRYPT);
    $update = $pdo->prepare('UPDATE users SET password_hash = :hash, updated_at = NOW() WHERE id = :id');
    $update->execute([':hash' => $newHash, ':id' => $userId]);

    // Rodada 9 (2026-08-19): regenera o ID de sessao apos troca de senha
    // (mesma pratica que auth/login.php ja usa no login) -- reduz a janela
    // de session fixation neste fluxo, que roda dentro de uma sessao ja
    // autenticada. Nao resolve a invalidacao de sessoes em OUTROS
    // dispositivos/navegadores (precisaria de coluna nova em `users` +
    // checagem no bootstrap de sessao -- estrutural, deixado pra aprovacao
    // do Fred). Ver R9-8 no relatorio da Rodada 9.
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_regenerate_id(true);
    }

    echo json_encode(['ok' => true]);
} catch (Throwable $e) {
    error_log('[MinhaConta] password-change failed: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'internal_error']);
}

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

$name = trim((string)($body['name'] ?? ''));
$phone = preg_replace('/[^0-9()+\-\s]/', '', (string)($body['phone'] ?? ''));
$cpf = preg_replace('/\D+/', '', (string)($body['cpf'] ?? ''));

if ($name === '' || strlen($name) > 255) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'invalid_name']);
    exit;
}
if ($cpf !== '' && strlen($cpf) !== 11) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'invalid_cpf']);
    exit;
}

try {
    $pdo = sv_pdo();
    $userId = (int)$_SESSION['user_id'];

    $stmt = $pdo->prepare('UPDATE users SET name = :name, phone = :phone, cpf = :cpf, updated_at = NOW() WHERE id = :id');
    $stmt->execute([
        ':name' => $name,
        ':phone' => $phone !== '' ? $phone : null,
        ':cpf' => $cpf !== '' ? $cpf : null,
        ':id' => $userId,
    ]);

    $_SESSION['user_name'] = $name;

    echo json_encode(['ok' => true]);
} catch (Throwable $e) {
    error_log('[MinhaConta] profile-save failed: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'internal_error']);
}

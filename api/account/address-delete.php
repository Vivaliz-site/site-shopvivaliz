<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once dirname(__DIR__, 2) . '/includes/account-chrome.php';
require_once dirname(__DIR__, 2) . '/includes/pdo-database.php';
require_once dirname(__DIR__, 2) . '/includes/account-schema.php';
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

$id = (int)($body['id'] ?? 0);
if ($id <= 0) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'invalid_id']);
    exit;
}

try {
    sv_account_ensure_schema();
    $pdo = sv_pdo();
    $stmt = $pdo->prepare('DELETE FROM addresses WHERE id = :id AND user_id = :uid');
    $stmt->execute([':id' => $id, ':uid' => (int)$_SESSION['user_id']]);

    if ($stmt->rowCount() === 0) {
        http_response_code(404);
        echo json_encode(['ok' => false, 'error' => 'not_found']);
        exit;
    }

    echo json_encode(['ok' => true]);
} catch (Throwable $e) {
    error_log('[MinhaConta] address-delete failed: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'internal_error']);
}

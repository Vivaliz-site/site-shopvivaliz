<?php
declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/pdo-database.php';
require_once __DIR__ . '/../core/logger/logger.php';

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Expires: 0');

if (empty($_SESSION['user_id'])) {
    header('Location: /auth/login.php?redirect=' . urlencode($_SERVER['REQUEST_URI'] ?? '/admin/'));
    exit;
}

$sessionMaxAge = 3600;
$sessionIssuedAt = (int)($_SESSION['issued_at'] ?? $_SESSION['login_at'] ?? 0);
if ($sessionIssuedAt > 0 && (time() - $sessionIssuedAt) > $sessionMaxAge) {
    sv_log('admin_guard_session_expired', 'security', [
        'user_id' => (int)($_SESSION['user_id'] ?? 0),
        'uri' => $_SERVER['REQUEST_URI'] ?? '',
    ]);
    session_unset();
    session_destroy();
    header('Location: /auth/login.php?expired=1&redirect=' . urlencode($_SERVER['REQUEST_URI'] ?? '/admin/'));
    exit;
}

if (empty($_SESSION['is_admin']) || !is_numeric($_SESSION['is_admin'])) {
    $isAdmin = false;
    try {
        if (function_exists('sv_pdo')) {
            $db = sv_pdo();
            if ($db instanceof PDO) {
                $stmt = $db->prepare('SELECT is_admin FROM users WHERE id = ? LIMIT 1');
                $stmt->execute([(int)$_SESSION['user_id']]);
                $row = $stmt->fetch(PDO::FETCH_ASSOC);
                $isAdmin = !empty($row) && !empty($row['is_admin']);
            }
        } elseif (class_exists('Database')) {
            $conn = Database::getInstance()->getConnection();
            if ($conn instanceof mysqli) {
                $stmt = $conn->prepare('SELECT is_admin FROM users WHERE id = ? LIMIT 1');
                if ($stmt) {
                    $userId = (int)$_SESSION['user_id'];
                    $stmt->bind_param('i', $userId);
                    $stmt->execute();
                    $row = $stmt->get_result()->fetch_assoc();
                    $isAdmin = !empty($row) && !empty($row['is_admin']);
                }
            }
        }
    } catch (Throwable $e) {
        sv_log('admin_guard_error', 'security', ['error' => $e->getMessage()]);
        $isAdmin = false;
    }

    $_SESSION['is_admin'] = $isAdmin ? 1 : 0;
    if (!$isAdmin) {
        sv_log('admin_guard_denied', 'security', ['user_id' => (int)$_SESSION['user_id'], 'uri' => $_SERVER['REQUEST_URI'] ?? '']);
    }
}

if (empty($_SESSION['is_admin'])) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'Acesso negado.';
    exit;
}

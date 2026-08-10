<?php
declare(strict_types=1);

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/../core/logger/logger.php';

if (empty($_SESSION['user_id'])) {
    header('Location: /auth/login.php?redirect=' . urlencode($_SERVER['REQUEST_URI'] ?? '/admin/'));
    exit;
}

if (empty($_SESSION['is_admin'])) {
    require_once __DIR__ . '/../config/database.php';
    try {
        $db = Database::getInstance()->getConnection();
        $stmt = $db->prepare('SELECT id, is_admin FROM users WHERE id = ? LIMIT 1');
        $stmt->bind_param('i', $_SESSION['user_id']);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $_SESSION['is_admin'] = !empty($row['is_admin']) ? 1 : 0;
        if (empty($_SESSION['is_admin'])) {
            sv_log('admin_guard_denied', 'security', ['user_id' => (int)$_SESSION['user_id'], 'uri' => $_SERVER['REQUEST_URI'] ?? '']);
        }
    } catch (Throwable $e) {
        sv_log('admin_guard_error', 'security', ['error' => $e->getMessage()]);
        $_SESSION['is_admin'] = 0;
    }
}

if (empty($_SESSION['is_admin'])) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=UTF-8');
    echo 'Acesso negado.';
    exit;
}

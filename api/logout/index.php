<?php
declare(strict_types=1);

header('Content-Type: application/json');

// Incluir configuração e helpers
require_once __DIR__ . '/../../config/constants.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/auth-helpers.php';

// Apenas POST é permitido
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    exit;
}

// Fazer logout
if (is_logged_in()) {
    $user_id = $_SESSION['user_id'];
    log_activity($user_id, 'api_logout', 'Logout via API');
}

session_destroy();

// Limpar cookies de sessão
if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
}

http_response_code(200);
echo json_encode(['success' => true, 'message' => 'Logout realizado com sucesso']);

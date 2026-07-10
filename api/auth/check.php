<?php
declare(strict_types=1);

header('Content-Type: application/json');

// Incluir configuração e helpers
require_once __DIR__ . '/../../config/constants.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/auth-helpers.php';

// Retornar status de login
$isLoggedIn = is_logged_in();

if ($isLoggedIn) {
    $user = get_current_user();
    if ($user) {
        http_response_code(200);
        echo json_encode([
            'success' => true,
            'logged_in' => true,
            'user' => [
                'id' => $user['id'],
                'name' => $user['name'],
                'email' => $user['email'],
                'phone' => $user['phone'] ?? null,
                'cpf' => $user['cpf'] ?? null,
                'created_at' => $user['created_at'],
            ]
        ]);
        exit;
    }
}

http_response_code(200);
echo json_encode([
    'success' => true,
    'logged_in' => false,
    'user' => null
]);

<?php
/**
 * Mock Login com Google - Para desenvolvimento/testes
 * Simula um login bem-sucedido sem precisar de credenciais OAuth reais
 *
 * Use apenas em ambiente de desenvolvimento!
 *
 * Acesse: /auth/google-mock-login.php?email=seu@email.com
 */

declare(strict_types=1);

session_start();

// Aceita apenas em desenvolvimento
if (getenv('APP_ENV') === 'production') {
    http_response_code(403);
    die('Mock login não permitido em produção');
}

require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/social-auth.php';

$redirectTo = $_GET['redirect'] ?? '/';
if (!is_string($redirectTo) || ($redirectTo[0] !== '/' && $redirectTo !== '')) {
    $redirectTo = '/';
}

try {
    // Email de teste (ou do query param)
    $email = trim($_GET['email'] ?? 'teste@shopvivaliz.com');
    $name = trim($_GET['name'] ?? 'Usuário Teste');

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        throw new RuntimeException('Email inválido: ' . $email);
    }

    // Simular perfil do Google
    $googleProfile = [
        'provider_id' => 'mock-google-' . md5($email),
        'email' => $email,
        'email_verified' => true,
        'name' => $name,
        'avatar_url' => 'https://ui-avatars.com/api/?name=' . urlencode($name),
    ];

    // Criar/atualizar usuário
    $user = sv_social_upsert_user('google', $googleProfile);

    // Fazer login
    sv_social_login_user($user);

    // Redirecionar
    header('Location: ' . $redirectTo);
    exit;

} catch (Throwable $e) {
    http_response_code(400);
    echo '<h1>Erro no Mock Login</h1>';
    echo '<p>' . htmlspecialchars($e->getMessage()) . '</p>';
    echo '<a href="/auth/login.php">Voltar</a>';
    exit;
}

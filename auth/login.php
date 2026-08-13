<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/secure-session.php';
require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/social-auth.php';
require_once __DIR__ . '/../includes/input-validator.php';
require_once __DIR__ . '/../includes/rate-limiter.php';

$redirectTo = sv_social_sanitize_redirect((string)($_GET['redirect'] ?? $_POST['redirect'] ?? '/'));

if (!empty($_SESSION['user_id'])) {
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }
    header('Location: ' . $redirectTo);
    exit;
}

$error = '';
$email = '';
$oauthError = match ((string)($_GET['oauth_error'] ?? '')) {
    'google_unavailable' => 'O acesso pelo Google está temporariamente indisponível neste servidor. A configuração foi registrada para diagnóstico; tente novamente ou entre com email e senha.',
    'session_unavailable' => 'Não foi possível iniciar uma sessão segura com o Google. Recarregue a página e tente novamente.',
    default => '',
};

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && !sv_csrf_valid('auth-login', $_POST['csrf_token'] ?? null)) {
    $error = 'Sua sessão expirou. Recarregue a página e tente novamente.';
} elseif (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $clientIp = (string)($_SERVER['REMOTE_ADDR'] ?? 'unknown');
    if (!RateLimiter::isAllowed('login_' . $clientIp, 5, 60)) {
        $error = 'Muitas tentativas de login. Tente novamente em 1 minuto.';
        http_response_code(429);
    } else {
        $validator = validator();
        $email = (string)($validator->getEmail('email', true) ?? '');
        $password = $validator->getString('password', '', 1, 255);

        if ($email === '' || $validator->getError('email')) {
            $error = 'Email inválido.';
        } elseif ($password === '') {
            $error = 'Email e senha são obrigatórios.';
        } else {
            try {
                $db = Database::getInstance()->getConnection();
                $stmt = $db->prepare('SELECT id, name, email, password_hash FROM users WHERE email = ? LIMIT 1');
                if (!$stmt) {
                    throw new RuntimeException('Falha ao preparar a autenticação.');
                }
                $stmt->bind_param('s', $email);
                $stmt->execute();
                $result = $stmt->get_result();
                $user = $result ? $result->fetch_assoc() : null;
                $stmt->close();

                if (!is_array($user) || !password_verify($password, (string)($user['password_hash'] ?? ''))) {
                    $error = 'Email ou senha incorretos.';
                } else {
                    if (!session_regenerate_id(true)) {
                        throw new RuntimeException('Não foi possível renovar a sessão autenticada.');
                    }
                    $_SESSION['user_id'] = (int)$user['id'];
                    $_SESSION['user_name'] = (string)$user['name'];
                    $_SESSION['user_email'] = (string)$user['email'];
                    $_SESSION['issued_at'] = time();

                    $update = $db->prepare('UPDATE users SET updated_at = NOW() WHERE id = ?');
                    if ($update) {
                        $userId = (int)$user['id'];
                        $update->bind_param('i', $userId);
                        $update->execute();
                        $update->close();
                    }

                    if (session_status() === PHP_SESSION_ACTIVE && !session_write_close()) {
                        throw new RuntimeException('Não foi possível persistir a sessão autenticada.');
                    }
                    header('Location: ' . $redirectTo);
                    exit;
                }
            } catch (Throwable $exception) {
                error_log('[auth/login] ' . $exception->getMessage());
                $error = 'Erro ao conectar ao sistema de autenticação. Tente novamente.';
            }
        }
    }
}

$googleStartUrl = '/auth/google-start.php?' . http_build_query(['redirect' => $redirectTo]);
$isProduction = strtolower((string)(getenv('APP_ENV') ?: ($_ENV['APP_ENV'] ?? 'production'))) === 'production';
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title>Login - ShopVivaliz</title>
    <link rel="stylesheet" href="/css/dazzle-v1.css?v=1.2.0">
    <style>
        *{box-sizing:border-box}html{-webkit-text-size-adjust:100%;text-size-adjust:100%}body{margin:0;min-height:100vh;display:grid;place-items:center;padding:20px;background:linear-gradient(135deg,#667eea 0%,#764ba2 100%);font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,"Helvetica Neue",Arial,sans-serif;color:#172033}.login-card{width:min(430px,100%);background:#fff;border-radius:16px;box-shadow:0 20px 60px rgba(15,23,42,.28);padding:34px}.logo{text-align:center;font-size:28px;font-weight:900;color:#2d3250;margin-bottom:22px}h1{font-size:24px;line-height:1.2;text-align:center;margin:0 0 7px}.subtitle{text-align:center;color:#64748b;font-size:14px;line-height:1.45;margin:0 0 24px}.alert{padding:11px 12px;border-radius:9px;margin:0 0 16px;font-size:13px;line-height:1.45}.alert.error{background:#fef2f2;color:#991b1b;border:1px solid #fecaca}.alert.oauth{background:#fff8e1;color:#6b5300;border:1px solid #f4dda0}.form-group{margin-bottom:17px}.form-group>label{display:block;margin-bottom:7px;font-weight:750;font-size:14px}.form-group input{width:100%;min-height:46px;padding:11px 12px;border:1px solid #cbd5e1;border-radius:9px;font:inherit;font-size:16px;background:#fff}.form-group input:focus{outline:0;border-color:#667eea;box-shadow:0 0 0 3px rgba(102,126,234,.15)}.password-field{position:relative}.password-field input{padding-right:92px}.password-toggle{position:absolute;right:7px;top:50%;transform:translateY(-50%);width:auto;min-width:74px;min-height:34px;margin:0;padding:7px 9px;border:1px solid #d1d5db;border-radius:999px;background:#fff;color:#374151;font-size:12px;font-weight:800;cursor:pointer}.btn-primary,.btn-social{width:100%;min-height:48px;border-radius:9px;font:inherit;font-weight:850;cursor:pointer;text-decoration:none}.btn-primary{border:0;background:#667eea;color:#fff;padding:12px}.btn-primary:hover{background:#5568d3}.divider{display:flex;align-items:center;gap:10px;margin:24px 0 16px;color:#94a3b8;font-size:12px;font-weight:750}.divider::before,.divider::after{content:"";height:1px;background:#e2e8f0;flex:1}.btn-social{display:flex;align-items:center;justify-content:center;gap:9px;padding:11px 13px;border:1px solid #d1d5db;background:#fff;color:#202124}.btn-social:hover{background:#f8fafc;border-color:#94a3b8;box-shadow:0 3px 12px rgba(66,133,244,.14)}.btn-social svg{width:20px;height:20px;flex:0 0 auto}.footer-link{text-align:center;margin-top:17px;font-size:13px;color:#64748b}.footer-link a{color:#5b63d3;text-decoration:none;font-weight:800}.footer-link a:hover{text-decoration:underline}.oauth-help{margin:9px 0 0;text-align:center;color:#64748b;font-size:11px;line-height:1.4}@media(max-width:520px){body{padding:10px;align-items:end}.login-card{padding:24px 18px calc(22px + env(safe-area-inset-bottom));border-radius:16px 16px 0 0}.logo{font-size:24px;margin-bottom:17px}h1{font-size:21px}.subtitle{margin-bottom:18px}}
    </style>
</head>
<body>
<main class="login-card">
    <div class="logo">ShopVivaliz</div>
    <h1>Acesse sua conta</h1>
    <p class="subtitle">Entre para continuar no site ou retornar à área administrativa.</p>

    <?php if ($error !== ''): ?><div class="alert error" role="alert"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
    <?php if ($oauthError !== ''): ?><div class="alert oauth" role="alert"><?= htmlspecialchars($oauthError, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>

    <form method="post">
        <?= sv_csrf_input('auth-login') ?>
        <input type="hidden" name="redirect" value="<?= htmlspecialchars($redirectTo, ENT_QUOTES, 'UTF-8') ?>">
        <div class="form-group">
            <label for="email">Email</label>
            <input type="email" id="email" name="email" required autocomplete="email" value="<?= htmlspecialchars($email, ENT_QUOTES, 'UTF-8') ?>" placeholder="seu@email.com">
        </div>
        <div class="form-group">
            <label for="password">Senha</label>
            <div class="password-field">
                <input type="password" id="password" name="password" required autocomplete="current-password" placeholder="••••••••">
                <button type="button" class="password-toggle" data-password-toggle="password" aria-pressed="false" aria-label="Mostrar senha">Mostrar</button>
            </div>
        </div>
        <button type="submit" class="btn-primary">Entrar</button>
    </form>

    <div class="footer-link"><a href="/auth/forgot-password.php">Esqueci minha senha</a></div>
    <div class="divider">OU</div>

    <a href="<?= htmlspecialchars($googleStartUrl, ENT_QUOTES, 'UTF-8') ?>" class="btn-social btn-google" title="Entrar com Google">
        <svg viewBox="0 0 24 24" aria-hidden="true">
            <path d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92c-.26 1.37-1.04 2.53-2.21 3.31v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.09z" fill="#4285F4"/><path d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z" fill="#34A853"/><path d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.07H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.93l2.85-2.22.81-.62z" fill="#FBBC05"/><path d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 0 3.99 3.47 2.18 7.07l3.66 2.84c.87-2.6 3.3-4.53 6.16-4.53z" fill="#EA4335"/>
        </svg>
        Entrar com Google
    </a>
    <p class="oauth-help">O botão permanece visível. A configuração é validada somente quando você inicia o acesso.</p>

    <?php if (!$isProduction && !sv_social_google_is_configured()): ?>
        <div class="footer-link"><a href="/auth/google-mock-login.php?redirect=<?= urlencode($redirectTo) ?>">Usar conta de teste local</a></div>
    <?php endif; ?>
    <div class="footer-link">Não tem conta? <a href="/auth/register.php">Cadastre-se aqui</a></div>
</main>
<script>
(() => {
    'use strict';
    document.querySelectorAll('[data-password-toggle]').forEach((button) => {
        button.addEventListener('click', () => {
            const target = document.getElementById(button.getAttribute('data-password-toggle') || '');
            if (!target) return;
            const show = target.type === 'password';
            target.type = show ? 'text' : 'password';
            button.textContent = show ? 'Ocultar' : 'Mostrar';
            button.setAttribute('aria-pressed', show ? 'true' : 'false');
            button.setAttribute('aria-label', show ? 'Ocultar senha' : 'Mostrar senha');
        });
    });
})();
</script>
</body>
</html>

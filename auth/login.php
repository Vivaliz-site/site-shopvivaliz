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
    header('Location: ' . $redirectTo);
    exit;
}

$error = '';
$email = '';

// Processar login
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !sv_csrf_valid('auth-login', $_POST['csrf_token'] ?? null)) {
    $error = 'Sua sessão expirou. Recarregue a página e tente novamente.';
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $clientIp = $_SERVER['REMOTE_ADDR'];

    // ✅ Rate limiting: máximo 5 tentativas por minuto por IP
    if (!RateLimiter::isAllowed('login_' . $clientIp, 5, 60)) {
        $error = 'Muitas tentativas de login. Tente novamente em 1 minuto.';
        http_response_code(429);
    } else {
        // ✅ Input validation com InputValidator
        $v = validator();
        $email = $v->getEmail('email', true);
        $password = $v->getString('password', '', 8, 255);

        if ($email === null || $v->getError('email')) {
            $error = 'Email inválido';
        } elseif ($password === '' || strlen($password) < 1) {
            $error = 'Email e senha são obrigatórios';
        } else {
        try {
            $db = Database::getInstance()->getConnection();

            $stmt = $db->prepare('SELECT id, name, email, password_hash FROM users WHERE email = ? LIMIT 1');
            if ($stmt) {
                $stmt->bind_param('s', $email);
                $stmt->execute();
                $result = $stmt->get_result();
                $user = $result->fetch_assoc();

                if ($user && password_verify($password, $user['password_hash'])) {
                    // Regenerar session ID para prevenir Session Fixation
                    session_regenerate_id(true);

                    // Login bem-sucedido
                    $_SESSION['user_id'] = $user['id'];
                    $_SESSION['user_name'] = $user['name'];
                    $_SESSION['user_email'] = $user['email'];

                    // Atualizar updated_at (coluna last_login nao existe no schema)
                    $update = $db->prepare('UPDATE users SET updated_at = NOW() WHERE id = ?');
                    if ($update) {
                        $update->bind_param('i', $user['id']);
                        $update->execute();
                    }

                    header('Location: ' . $redirectTo);
                    exit;
                } else {
                    $error = 'Email ou senha incorretos';
                }
            }
        } catch (Exception $e) {
            error_log('[auth/login] ' . $e->getMessage());
            $error = 'Erro ao conectar ao banco de dados';
        }
    }
}

$google_auth_url = sv_social_google_auth_url('login', $redirectTo);
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - ShopVivaliz</title>
    <link rel="stylesheet" href="/css/dazzle-v1.css?v=1.2.0">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        .container {
            background: white;
            border-radius: 10px;
            box-shadow: 0 10px 40px rgba(0, 0, 0, 0.2);
            max-width: 420px;
            width: 100%;
            padding: 40px;
        }
        .logo {
            text-align: center;
            margin-bottom: 30px;
            font-size: 28px;
            font-weight: bold;
            color: #333;
        }
        h1 {
            font-size: 24px;
            margin-bottom: 10px;
            color: #333;
            text-align: center;
        }
        .subtitle {
            text-align: center;
            color: #666;
            font-size: 14px;
            margin-bottom: 30px;
        }
        .form-group {
            margin-bottom: 20px;
        }
        label {
            display: block;
            margin-bottom: 8px;
            font-weight: 500;
            color: #333;
            font-size: 14px;
        }
        input[type="email"],
        input[type="password"] {
            width: 100%;
            padding: 12px;
            border: 1px solid #ddd;
            border-radius: 6px;
            font-size: 14px;
            transition: border-color 0.3s;
        }
        input[type="email"]:focus,
        input[type="password"]:focus {
            outline: none;
            border-color: #667eea;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }
        .password-field {
            position: relative;
        }
        .password-field input {
            padding-right: 92px;
        }
        .password-toggle {
            position: absolute;
            right: 8px;
            top: 50%;
            transform: translateY(-50%);
            width: auto;
            min-width: 74px;
            padding: 8px 10px;
            margin-top: 0;
            border: 1px solid #d1d5db;
            border-radius: 999px;
            background: #fff;
            color: #374151;
            font-size: 12px;
            font-weight: 700;
            line-height: 1;
            box-shadow: none;
        }
        .password-toggle:hover {
            background: #f8fafc;
            box-shadow: none;
        }
        .error {
            background: #fee;
            color: #c33;
            padding: 12px;
            border-radius: 6px;
            margin-bottom: 20px;
            font-size: 14px;
            border-left: 4px solid #c33;
        }
        button {
            width: 100%;
            padding: 12px;
            border: none;
            border-radius: 6px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            margin-top: 10px;
        }
        .btn-primary {
            background: #667eea;
            color: white;
        }
        .btn-primary:hover {
            background: #5568d3;
            box-shadow: 0 4px 15px rgba(102, 126, 234, 0.4);
        }
        .btn-social {
            background: #f5f5f5;
            color: #333;
            border: 1px solid #ddd;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            font-size: 14px;
            font-weight: 500;
        }
        .btn-social:hover {
            background: #efefef;
            border-color: #bbb;
        }
        .btn-social:disabled,
        .btn-disabled {
            opacity: 0.6;
            cursor: not-allowed;
            background: #f0f0f0;
        }
        .btn-google:hover {
            background: #f0f0f0;
            box-shadow: 0 2px 8px rgba(66, 133, 244, 0.2);
        }
        .btn-apple:hover {
            background: #f0f0f0;
            box-shadow: 0 2px 8px rgba(0, 0, 0, 0.15);
        }
        .btn-social svg {
            width: 18px;
            height: 18px;
        }
        .divider {
            display: flex;
            align-items: center;
            gap: 10px;
            margin: 30px 0 20px;
            color: #999;
            font-size: 13px;
        }
        .divider::before,
        .divider::after {
            content: '';
            flex: 1;
            height: 1px;
            background: #ddd;
        }
        .social-buttons {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 10px;
        }
        .footer-link {
            text-align: center;
            margin-top: 20px;
            font-size: 14px;
            color: #666;
        }
        .footer-link a {
            color: #667eea;
            text-decoration: none;
            font-weight: 600;
        }
        .footer-link a:hover {
            text-decoration: underline;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="logo">ShopVivaliz</div>

        <h1>Acesse sua conta</h1>
        <p class="subtitle">Faça login para continuar as compras</p>

        <?php if ($error): ?>
        <div class="error"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <form method="POST">
            <?= sv_csrf_input('auth-login') ?>
            <input type="hidden" name="redirect" value="<?= htmlspecialchars($redirectTo, ENT_QUOTES, 'UTF-8') ?>">
            <div class="form-group">
                <label for="email">Email</label>
                <input type="email" id="email" name="email" required
                    value="<?php echo htmlspecialchars($email); ?>"
                    placeholder="seu@email.com">
            </div>

            <div class="form-group">
                <label for="password">Senha</label>
                <div class="password-field">
                    <input type="password" id="password" name="password" required
                        placeholder="••••••••" autocomplete="current-password">
                    <button type="button" class="password-toggle" data-password-toggle="password" aria-pressed="false" aria-label="Mostrar senha">Mostrar</button>
                </div>
            </div>

            <button type="submit" class="btn-primary">Entrar</button>
        </form>

        <div class="footer-link" style="margin-top:12px;">
            <a href="/auth/forgot-password.php">Esqueci minha senha</a>
        </div>

        <div class="divider">OU</div>

        <div class="social-buttons">
            <?php if ($google_auth_url !== ''): ?>
            <a href="<?php echo htmlspecialchars($google_auth_url); ?>" class="btn-social btn-google" title="Continuar com Google" style="grid-column: 1 / -1;">
                <svg viewBox="0 0 24 24" fill="currentColor">
                    <path d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92c-.26 1.37-1.04 2.53-2.21 3.31v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.09z" fill="#4285F4"/><path d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z" fill="#34A853"/><path d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.07H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.93l2.85-2.22.81-.62z" fill="#FBBC05"/><path d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.07l3.66 2.84c.87-2.6 3.3-4.53 6.16-4.53z" fill="#EA4335"/>
                </svg>
                Continuar com Google
            </a>
            <?php elseif (strtolower((string)(getenv('APP_ENV') ?: ($_ENV['APP_ENV'] ?? 'production'))) !== 'production'): ?>
            <a href="/auth/google-mock-login.php?redirect=<?= urlencode($redirectTo) ?>" class="btn-social btn-google" style="grid-column: 1 / -1;" title="Teste com conta padrão">
                <svg viewBox="0 0 24 24" fill="currentColor">
                    <path d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92c-.26 1.37-1.04 2.53-2.21 3.31v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.09z" fill="#4285F4"/><path d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z" fill="#34A853"/><path d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.07H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.93l2.85-2.22.81-.62z" fill="#FBBC05"/><path d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.07l3.66 2.84c.87-2.6 3.3-4.53 6.16-4.53z" fill="#EA4335"/>
                </svg>
                Continuar com Google (TESTE)
            </a>
            <?php endif; ?>
        </div>

        <div class="footer-link">
            Não tem conta? <a href="/auth/register.php">Cadastre-se aqui</a>
        </div>
    </div>
    <script>
    (function () {
        document.querySelectorAll('[data-password-toggle]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var targetId = btn.getAttribute('data-password-toggle');
                var input = targetId ? document.getElementById(targetId) : null;
                if (!input) return;
                var hidden = input.type === 'password';
                input.type = hidden ? 'text' : 'password';
                btn.setAttribute('aria-pressed', hidden ? 'true' : 'false');
                btn.setAttribute('aria-label', hidden ? 'Ocultar senha' : 'Mostrar senha');
                btn.textContent = hidden ? 'Ocultar' : 'Mostrar';
            });
        });
    })();
    </script>
</body>
</html>

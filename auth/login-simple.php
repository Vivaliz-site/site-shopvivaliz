<?php
/**
 * Login Simplificado - Sem dependências de BD
 * Para desenvolvimento/testes locais
 */

session_start();

require_once __DIR__ . '/../includes/social-auth.php';

$redirectTo = (string)($_GET['redirect'] ?? $_POST['redirect'] ?? '/');
if ($redirectTo === '' || $redirectTo[0] !== '/' || str_starts_with($redirectTo, '//')) {
    $redirectTo = '/';
}

if (!empty($_SESSION['user_id'])) {
    header('Location: ' . $redirectTo);
    exit;
}

$error = '';
$email = '';

// Mock login - aceita qualquer email
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    if (empty($email) || empty($password)) {
        $error = 'Email e senha são obrigatórios';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Email inválido';
    } else {
        // Mock login bem-sucedido (desenvolvimento)
        $_SESSION['user_id'] = uniqid('user_');
        $_SESSION['user_name'] = strtok($email, '@');
        $_SESSION['user_email'] = $email;

        header('Location: ' . $redirectTo);
        exit;
    }
}

if (sv_social_google_is_configured()) {
    $google_auth_url = sv_social_google_auth_url('login', $redirectTo);
    $google_button_label = 'Continuar com Google';
} else {
    // Sem credenciais OAuth configuradas: usa mock apenas fora de produção.
    $appEnv = strtolower((string)(getenv('APP_ENV') ?: ($_ENV['APP_ENV'] ?? 'production')));
    if ($appEnv === 'production') {
        $google_auth_url = '';
        $google_button_label = '';
    } else {
        $google_auth_url = '/auth/google-mock-login.php?redirect=' . urlencode($redirectTo);
        $google_button_label = 'Continuar com Google (TESTE)';
    }
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - ShopVivaliz</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
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
            text-decoration: none;
        }
        .btn-social:hover {
            background: #efefef;
            border-color: #bbb;
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
                        placeholder="••••••••">
                    <button type="button" class="password-toggle" data-password-toggle="password" aria-pressed="false" aria-label="Mostrar senha">Mostrar</button>
                </div>
            </div>

            <button type="submit" class="btn-primary">Entrar</button>
        </form>

        <?php if ($google_auth_url !== ''): ?>
        <div class="divider">OU</div>
        <div class="social-buttons">
            <a href="<?php echo htmlspecialchars($google_auth_url); ?>" class="btn-social" style="grid-column: 1 / -1;">
                <svg viewBox="0 0 24 24" fill="currentColor">
                    <path d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92c-.26 1.37-1.04 2.53-2.21 3.31v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.09z" fill="#4285F4"/><path d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z" fill="#34A853"/><path d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.07H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.93l2.85-2.22.81-.62z" fill="#FBBC05"/><path d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.07l3.66 2.84c.87-2.6 3.3-4.53 6.16-4.53z" fill="#EA4335"/>
                </svg>
                <?php echo htmlspecialchars($google_button_label); ?>
            </a>
        </div>
        <?php endif; ?>

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

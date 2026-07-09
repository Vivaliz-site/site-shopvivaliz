<?php
/**
 * Admin Guard — Proteção para páginas administrativas
 * Requer login via session
 */
declare(strict_types=1);

// Carregar .env se não estiver carregado
if (!function_exists('admin_load_env')) {
    function admin_load_env(): void {
        static $loaded = false;
        if ($loaded) return;

        $envFile = dirname(__DIR__) . '/.env';
        if (is_file($envFile)) {
            foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
                $line = trim($line);
                if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) continue;
                [$k, $v] = explode('=', $line, 2);
                $k = trim($k); $v = trim(trim($v), '"\'');
                if ($k !== '' && getenv($k) === false) putenv("$k=$v");
            }
        }

        $loaded = true;
    }
}

admin_load_env();

// Iniciar sessão
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Verificar login
if (empty($_SESSION['admin_logged_in'])) {
    // Render login form (simples, inline)
    header('Content-Type: text/html; charset=utf-8');
    ?>
    <!DOCTYPE html>
    <html lang="pt-BR">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>Admin Login — ShopVivaliz</title>
        <style>
            * {
                margin: 0;
                padding: 0;
                box-sizing: border-box;
            }
            body {
                font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
                background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
                min-height: 100vh;
                display: flex;
                align-items: center;
                justify-content: center;
            }
            .login-container {
                background: white;
                padding: 40px;
                border-radius: 12px;
                box-shadow: 0 10px 40px rgba(0,0,0,0.2);
                width: 100%;
                max-width: 400px;
            }
            .login-container h1 {
                font-size: 24px;
                margin-bottom: 10px;
                color: #333;
            }
            .login-container p {
                color: #666;
                margin-bottom: 30px;
                font-size: 14px;
            }
            .form-group {
                margin-bottom: 20px;
            }
            .form-group label {
                display: block;
                margin-bottom: 8px;
                color: #333;
                font-weight: 600;
                font-size: 14px;
            }
            .form-group input {
                width: 100%;
                padding: 12px;
                border: 1px solid #ddd;
                border-radius: 6px;
                font-size: 14px;
                transition: border-color 0.3s;
            }
            .form-group input:focus {
                outline: none;
                border-color: #667eea;
                box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
            }
            button {
                width: 100%;
                padding: 12px;
                background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
                color: white;
                border: none;
                border-radius: 6px;
                font-weight: 600;
                font-size: 14px;
                cursor: pointer;
                transition: transform 0.2s, box-shadow 0.2s;
            }
            button:hover {
                transform: translateY(-2px);
                box-shadow: 0 5px 20px rgba(102, 126, 234, 0.3);
            }
            button:active {
                transform: translateY(0);
            }
            .error {
                background: #fee;
                color: #c33;
                padding: 12px;
                border-radius: 6px;
                margin-bottom: 20px;
                font-size: 14px;
            }
            .help-text {
                margin-top: 20px;
                padding-top: 20px;
                border-top: 1px solid #eee;
                color: #999;
                font-size: 12px;
                line-height: 1.6;
            }
        </style>
    </head>
    <body>
        <div class="login-container">
            <h1>🔐 Admin</h1>
            <p>ShopVivaliz Editor Visual</p>

            <?php if (!empty($_POST) && empty($_POST['password'])): ?>
                <div class="error">❌ Senha obrigatória</div>
            <?php endif; ?>

            <?php if (!empty($_POST) && !empty($_POST['password'])):
                $pwd = (string)($_POST['password'] ?? '');
                $expectedPwd = getenv('ADMIN_PASSWORD') ?: 'shopvivaliz2024';
                if ($pwd !== $expectedPwd):
            ?>
                <div class="error">❌ Senha incorreta</div>
            <?php
                else:
                    $_SESSION['admin_logged_in'] = true;
                    $_SESSION['admin_login_time'] = time();
                    header('Location: ' . ($_GET['return'] ?? $_SERVER['HTTP_REFERER'] ?? '/admin/'));
                    exit;
                endif;
            ?>
            <?php endif; ?>

            <form method="POST">
                <div class="form-group">
                    <label for="password">Senha do Admin</label>
                    <input type="password" id="password" name="password" placeholder="••••••••" required autofocus>
                </div>
                <button type="submit">Entrar</button>
            </form>

            <div class="help-text">
                💡 Use a senha configurada em <code>.env</code><br>
                Variável: <code>ADMIN_PASSWORD</code><br>
                Padrão: <code>shopvivaliz2024</code>
            </div>
        </div>
    </body>
    </html>
    <?php
    exit;
}

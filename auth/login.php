<?php
declare(strict_types=1);

session_start();

// So aceita redirects internos (comeca com "/", nunca "//" que seria
// interpretado como URL absoluta por outro host -- evita open redirect).
$redirectTo = (string)($_GET['redirect'] ?? $_POST['redirect'] ?? '/');
if ($redirectTo === '' || $redirectTo[0] !== '/' || str_starts_with($redirectTo, '//')) {
    $redirectTo = '/';
}

// Se já está logado, redireciona para home
if (!empty($_SESSION['user_id'])) {
    header('Location: ' . $redirectTo);
    exit;
}

error_reporting(E_ALL);
ini_set('display_errors', '0');

require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/../config/database.php';

$error = '';
$email = '';

// Processar login
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';

    if (empty($email) || empty($password)) {
        $error = 'Email e senha são obrigatórios';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Email inválido';
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

// OAuth URLs
$google_client_id = getenv('GOOGLE_OAUTH_CLIENT_ID') ?: '';
$google_redirect_uri = 'https://' . ($_SERVER['HTTP_HOST'] ?? 'dev.shopvivaliz.com.br') . '/auth/google-callback.php';
$google_auth_url = $google_client_id ? 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query([
    'client_id' => $google_client_id,
    'redirect_uri' => $google_redirect_uri,
    'response_type' => 'code',
    'scope' => 'openid email profile',
    'state' => bin2hex(random_bytes(16)),
]) : '';

$apple_client_id = getenv('APPLE_OAUTH_CLIENT_ID') ?: '';
$apple_team_id = getenv('APPLE_TEAM_ID') ?: '';
$apple_key_id = getenv('APPLE_KEY_ID') ?: '';
$apple_redirect_uri = 'https://' . ($_SERVER['HTTP_HOST'] ?? 'dev.shopvivaliz.com.br') . '/auth/apple-callback.php';
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
            <input type="hidden" name="redirect" value="<?= htmlspecialchars($redirectTo, ENT_QUOTES, 'UTF-8') ?>">
            <div class="form-group">
                <label for="email">Email</label>
                <input type="email" id="email" name="email" required
                    value="<?php echo htmlspecialchars($email); ?>"
                    placeholder="seu@email.com">
            </div>

            <div class="form-group">
                <label for="password">Senha</label>
                <input type="password" id="password" name="password" required
                    placeholder="••••••••">
            </div>

            <button type="submit" class="btn-primary">Entrar</button>
        </form>

        <div class="divider">OU</div>

        <div class="social-buttons">
            <?php if ($google_auth_url): ?>
            <a href="<?php echo htmlspecialchars($google_auth_url); ?>" class="btn-social">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <circle cx="12" cy="12" r="10"></circle>
                </svg>
                Google
            </a>
            <?php endif; ?>

            <?php if ($apple_client_id): ?>
            <a href="<?php echo htmlspecialchars($apple_redirect_uri); ?>?action=login" class="btn-social">
                <svg viewBox="0 0 24 24" fill="currentColor">
                    <path d="M12.3 12.033c-.348 0-.895.04-1.36.04-2.48 0-4.86-.91-6.7-2.59-.22-.2-.05-.55.18-.48.76.18 1.84.3 2.6.3.46 0 .92-.04 1.38-.04 2.48 0 4.86.91 6.7 2.59.22.2.05.55-.18.48z"></path>
                </svg>
                Apple
            </a>
            <?php endif; ?>
        </div>

        <div class="footer-link">
            Não tem conta? <a href="/auth/register.php">Cadastre-se aqui</a>
        </div>
    </div>
</body>
</html>

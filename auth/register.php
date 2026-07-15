<?php
declare(strict_types=1);

session_start();

if (!empty($_SESSION['user_id'])) {
    header('Location: /');
    exit;
}

error_reporting(E_ALL);
ini_set('display_errors', '0');

require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/social-auth.php';
require_once __DIR__ . '/../includes/csrf.php';

$error = '';
$success = '';
$name = '';
$email = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !sv_csrf_valid('auth-register', $_POST['csrf_token'] ?? null)) {
    $error = 'Sua sessão expirou. Recarregue a página e tente novamente.';
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $password_confirm = $_POST['password_confirm'] ?? '';

    // Validações
    if (empty($name)) {
        $error = 'Nome é obrigatório';
    } elseif (strlen($name) < 3) {
        $error = 'Nome deve ter pelo menos 3 caracteres';
    } elseif (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Email inválido';
    } elseif (empty($password)) {
        $error = 'Senha é obrigatória';
    } elseif (strlen($password) < 8) {
        $error = 'Senha deve ter pelo menos 8 caracteres';
    } elseif ($password !== $password_confirm) {
        $error = 'As senhas não conferem';
    } else {
        try {
            $db = Database::getInstance()->getConnection();

            // Verificar se email já existe
            $check = $db->prepare('SELECT id FROM users WHERE email = ? LIMIT 1');
            if ($check) {
                $check->bind_param('s', $email);
                $check->execute();
                $result = $check->get_result();

                if ($result->num_rows > 0) {
                    $error = 'Este email já está cadastrado';
                } else {
                    // Criar novo usuário
                    $password_hash = password_hash($password, PASSWORD_BCRYPT);
                    // NULL, nao string vazia: cpf tem UNIQUE KEY no schema,
                    // e '' colide para todo cadastro sem CPF (preenchido
                    // depois no checkout). NULL nao conflita com NULL.
                    $cpf = null;
                    $phone = '';

                    $insert = $db->prepare(
                        'INSERT INTO users (name, email, password_hash, phone, cpf, created_at, updated_at)
                         VALUES (?, ?, ?, ?, ?, NOW(), NOW())'
                    );

                    if ($insert) {
                        $insert->bind_param('sssss', $name, $email, $password_hash, $phone, $cpf);

                        if ($insert->execute()) {
                            $success = 'Cadastro realizado com sucesso! Você pode fazer login agora.';
                            $name = '';
                            $email = '';
                        } else {
                            $error = 'Erro ao criar a conta. Tente novamente.';
                        }
                    }
                }
            }
        } catch (Exception $e) {
            error_log('[auth/register] ' . $e->getMessage());
            $error = 'Erro ao conectar ao banco de dados';
        }
    }
}
$google_auth_url = sv_social_google_auth_url('register', '/');
$apple_auth_url = sv_social_apple_auth_url('register', '/');
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Cadastro - ShopVivaliz</title>
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
        input[type="text"],
        input[type="email"],
        input[type="password"] {
            width: 100%;
            padding: 12px;
            border: 1px solid #ddd;
            border-radius: 6px;
            font-size: 14px;
            transition: border-color 0.3s;
        }
        input:focus {
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
        .success {
            background: #efe;
            color: #3c3;
            padding: 12px;
            border-radius: 6px;
            margin-bottom: 20px;
            font-size: 14px;
            border-left: 4px solid #3c3;
        }
        button {
            width: 100%;
            padding: 12px;
            border: none;
            border-radius: 6px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            background: #667eea;
            color: white;
            transition: all 0.3s;
            margin-top: 10px;
        }
        button:hover {
            background: #5568d3;
            box-shadow: 0 4px 15px rgba(102, 126, 234, 0.4);
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
        .password-requirements {
            font-size: 12px;
            color: #666;
            margin-top: 6px;
            line-height: 1.4;
        }
        .divider {
            display: flex;
            align-items: center;
            gap: 10px;
            margin: 25px 0 20px;
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
        .social-button {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            width: 100%;
            padding: 12px;
            background: #f5f5f5;
            color: #333;
            border: 1px solid #ddd;
            border-radius: 6px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            text-decoration: none;
            transition: all 0.3s;
            margin-bottom: 10px;
        }
        .social-button:hover {
            background: #efefef;
            border-color: #bbb;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="logo">ShopVivaliz</div>

        <h1>Crie sua conta</h1>
        <p class="subtitle">Junte-se a nossa comunidade</p>

        <?php if ($error): ?>
        <div class="error"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <?php if ($success): ?>
        <div class="success"><?php echo htmlspecialchars($success); ?></div>
        <?php endif; ?>

        <form method="POST">
            <?= sv_csrf_input('auth-register') ?>
            <div class="form-group">
                <label for="name">Nome Completo</label>
                <input type="text" id="name" name="name" required
                    value="<?php echo htmlspecialchars($name); ?>"
                    placeholder="Seu nome">
            </div>

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
                <div class="password-requirements">
                    Mínimo 8 caracteres
                </div>
            </div>

            <div class="form-group">
                <label for="password_confirm">Confirmar Senha</label>
                <input type="password" id="password_confirm" name="password_confirm" required
                    placeholder="••••••••">
            </div>

            <button type="submit">Cadastrar</button>
        </form>

        <div class="divider">OU</div>

        <?php if ($google_auth_url !== ''): ?>
        <a href="<?= htmlspecialchars($google_auth_url, ENT_QUOTES, 'UTF-8') ?>" class="social-button">
            <span>●</span> Cadastrar com Google
        </a>
        <?php endif; ?>

        <?php if ($apple_auth_url !== ''): ?>
        <a href="<?= htmlspecialchars($apple_auth_url, ENT_QUOTES, 'UTF-8') ?>" class="social-button">
            <span>●</span> Cadastrar com Apple
        </a>
        <?php endif; ?>

        <div class="footer-link">
            Já tem conta? <a href="/auth/login.php">Faça login</a>
        </div>
    </div>
</body>
</html>

<?php
declare(strict_types=1);

session_start();

error_reporting(E_ALL);
ini_set('display_errors', '0');

require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/pdo-database.php';
require_once __DIR__ . '/../includes/account-schema.php';
require_once __DIR__ . '/../includes/csrf.php';

sv_account_ensure_schema();

$token = trim((string)($_GET['token'] ?? $_POST['token'] ?? ''));
$error = '';
$success = '';
$validToken = false;
$userId = null;

if ($token === '') {
    $error = 'Link inválido.';
} else {
    $tokenHash = hash('sha256', $token);
    $pdo = sv_pdo();
    $stmt = $pdo->prepare(
        'SELECT id, user_id FROM password_resets
         WHERE token_hash = :hash AND used_at IS NULL AND expires_at > NOW()
         LIMIT 1'
    );
    $stmt->execute([':hash' => $tokenHash]);
    $reset = $stmt->fetch();

    if (!$reset) {
        $error = 'Esse link já expirou ou já foi usado. Peça um novo link em "Esqueci minha senha".';
    } else {
        $validToken = true;
        $userId = (int)$reset['user_id'];
    }
}

if ($validToken && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!sv_csrf_valid('auth-reset-password', $_POST['csrf_token'] ?? null)) {
        $error = 'Sua sessão expirou. Recarregue a página e tente novamente.';
    } else {
        $password = (string)($_POST['password'] ?? '');
        $passwordConfirm = (string)($_POST['password_confirm'] ?? '');

        if (strlen($password) < 8) {
            $error = 'Senha deve ter pelo menos 8 caracteres.';
        } elseif ($password !== $passwordConfirm) {
            $error = 'As senhas não conferem.';
        } else {
            try {
                $db = Database::getInstance()->getConnection();
                $passwordHash = password_hash($password, PASSWORD_BCRYPT);

                $update = $db->prepare('UPDATE users SET password_hash = ?, updated_at = NOW() WHERE id = ?');
                $update->bind_param('si', $passwordHash, $userId);
                $update->execute();

                $pdo = sv_pdo();
                $markUsed = $pdo->prepare('UPDATE password_resets SET used_at = NOW() WHERE token_hash = :hash');
                $markUsed->execute([':hash' => $tokenHash]);

                $success = 'Senha redefinida com sucesso! Você já pode fazer login com a nova senha.';
                $validToken = false;
            } catch (Throwable $e) {
                error_log('[auth/reset-password] ' . $e->getMessage());
                $error = 'Erro ao redefinir a senha. Tente novamente.';
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Redefinir senha - ShopVivaliz</title>
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
        .logo { text-align: center; margin-bottom: 30px; font-size: 28px; font-weight: bold; color: #333; }
        h1 { font-size: 24px; margin-bottom: 10px; color: #333; text-align: center; }
        .subtitle { text-align: center; color: #666; font-size: 14px; margin-bottom: 30px; }
        .form-group { margin-bottom: 20px; }
        label { display: block; margin-bottom: 8px; font-weight: 500; color: #333; font-size: 14px; }
        input[type="password"] {
            width: 100%; padding: 12px; border: 1px solid #ddd; border-radius: 6px; font-size: 14px;
        }
        input:focus { outline: none; border-color: #667eea; box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1); }
        .error { background: #fee; color: #c33; padding: 12px; border-radius: 6px; margin-bottom: 20px; font-size: 14px; border-left: 4px solid #c33; }
        .success { background: #efe; color: #3c3; padding: 12px; border-radius: 6px; margin-bottom: 20px; font-size: 14px; border-left: 4px solid #3c3; }
        button {
            width: 100%; padding: 12px; border: none; border-radius: 6px; font-size: 16px; font-weight: 600;
            cursor: pointer; background: #667eea; color: white; margin-top: 10px;
        }
        button:hover { background: #5568d3; }
        .footer-link { text-align: center; margin-top: 20px; font-size: 14px; color: #666; }
        .footer-link a { color: #667eea; text-decoration: none; font-weight: 600; }
        .footer-link a:hover { text-decoration: underline; }
        .password-requirements { font-size: 12px; color: #666; margin-top: 6px; }
    </style>
</head>
<body>
    <div class="container">
        <div class="logo">ShopVivaliz</div>
        <h1>Redefinir senha</h1>

        <?php if ($error): ?>
        <div class="error"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <?php if ($success): ?>
        <div class="success"><?php echo htmlspecialchars($success); ?></div>
        <?php endif; ?>

        <?php if ($validToken): ?>
        <form method="POST">
            <?= sv_csrf_input('auth-reset-password') ?>
            <input type="hidden" name="token" value="<?php echo htmlspecialchars($token); ?>">
            <div class="form-group">
                <label for="password">Nova senha</label>
                <input type="password" id="password" name="password" required placeholder="••••••••">
                <div class="password-requirements">Mínimo 8 caracteres</div>
            </div>
            <div class="form-group">
                <label for="password_confirm">Confirmar nova senha</label>
                <input type="password" id="password_confirm" name="password_confirm" required placeholder="••••••••">
            </div>
            <button type="submit">Redefinir senha</button>
        </form>
        <?php endif; ?>

        <div class="footer-link">
            <a href="/auth/login.php">Voltar ao login</a>
        </div>
    </div>
</body>
</html>

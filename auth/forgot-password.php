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
require_once __DIR__ . '/../scripts/mailer.php';

sv_account_ensure_schema();

$error = '';
$sent = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !sv_csrf_valid('auth-forgot-password', $_POST['csrf_token'] ?? null)) {
    $error = 'Sua sessão expirou. Recarregue a página e tente novamente.';
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim((string)($_POST['email'] ?? ''));
    $cpfDigits = preg_replace('/\D/', '', (string)($_POST['cpf'] ?? ''));

    if ($email === '' && $cpfDigits === '') {
        $error = 'Informe seu email ou CPF/CNPJ cadastrado.';
    } elseif ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Informe um email válido.';
    } elseif ($cpfDigits !== '' && strlen($cpfDigits) !== 11 && strlen($cpfDigits) !== 14) {
        $error = 'CPF/CNPJ inválido.';
    } else {
        try {
            $db = Database::getInstance()->getConnection();
            if ($email !== '') {
                $stmt = $db->prepare('SELECT id, name, email FROM users WHERE email = ? LIMIT 1');
                $stmt->bind_param('s', $email);
            } else {
                $stmt = $db->prepare('SELECT id, name, email FROM users WHERE cpf = ? LIMIT 1');
                $stmt->bind_param('s', $cpfDigits);
            }
            $stmt->execute();
            $user = $stmt->get_result()->fetch_assoc();

            // Sempre mostra a mesma mensagem de sucesso, exista ou nao a
            // conta -- nao vazar pra quem esta tentando descobrir
            // emails/CPFs cadastrados se uma conta existe ou nao no sistema.
            // As instrucoes sempre vao pro email JA cadastrado na conta,
            // nunca exibido na tela -- assim quem "esqueceu o email" tambem
            // recupera o acesso sem a gente vazar qual email esta associado
            // aquele CPF pra quem esta perguntando.
            $sent = true;

            if ($user) {
                $email = (string)$user['email'];
                $token = bin2hex(random_bytes(32));
                $tokenHash = hash('sha256', $token);

                $pdo = sv_pdo();
                $insert = $pdo->prepare(
                    'INSERT INTO password_resets (user_id, token_hash, expires_at) VALUES (:uid, :hash, DATE_ADD(NOW(), INTERVAL 1 HOUR))'
                );
                $insert->execute([':uid' => $user['id'], ':hash' => $tokenHash]);

                $baseUrl = rtrim((string)(getenv('SHOPVIVALIZ_BASE_URL') ?: getenv('APP_URL') ?: getenv('SITE_URL') ?: BASE_URL), '/');
                $resetUrl = $baseUrl . '/auth/reset-password.php?token=' . urlencode($token);
                $name = htmlspecialchars((string)$user['name'], ENT_QUOTES, 'UTF-8');
                $html = "<h2>Oi {$name},</h2>"
                    . "<p>Recebemos um pedido para redefinir a senha da sua conta ShopVivaliz.</p>"
                    . "<p><a href='{$resetUrl}' style='background:#667eea;color:#fff;padding:12px 20px;text-decoration:none;border-radius:6px;display:inline-block'>Redefinir minha senha</a></p>"
                    . "<p>Esse link expira em 1 hora. Se você não pediu essa redefinição, ignore este email -- sua senha continua a mesma.</p>";

                try {
                    send_email(to: $email, subject: 'Redefinir sua senha - ShopVivaliz', html: $html);
                } catch (Throwable $e) {
                    error_log('[auth/forgot-password] falha ao enviar email: ' . $e->getMessage());
                }
            }
        } catch (Throwable $e) {
            error_log('[auth/forgot-password] ' . $e->getMessage());
            $error = 'Erro ao processar o pedido. Tente novamente.';
            $sent = false;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Esqueci minha senha - ShopVivaliz</title>
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
        input[type="email"] {
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
    </style>
</head>
<body>
    <div class="container">
        <div class="logo">ShopVivaliz</div>
        <h1>Esqueci minha senha</h1>
        <p class="subtitle">Informe seu email <strong>ou</strong> CPF/CNPJ cadastrado. Enviaremos um link de redefinição para o email da sua conta.</p>

        <?php if ($error): ?>
        <div class="error"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <?php if ($sent): ?>
        <div class="success">Se esse email estiver cadastrado, você vai receber um link para redefinir sua senha em instantes.</div>
        <?php else: ?>
        <form method="POST">
            <?= sv_csrf_input('auth-forgot-password') ?>
            <div class="form-group">
                <label for="email">Email</label>
                <input type="email" id="email" name="email" placeholder="seu@email.com">
            </div>
            <div class="form-group">
                <label for="cpf">Ou CPF/CNPJ cadastrado</label>
                <input type="text" id="cpf" name="cpf" inputmode="numeric" maxlength="18" placeholder="Preencha se não lembra o email">
            </div>
            <button type="submit">Enviar link de redefinição</button>
        </form>
        <?php endif; ?>

        <div class="footer-link">
            <a href="/auth/login.php">Voltar ao login</a>
        </div>
    </div>
</body>
</html>

<?php
/**
 * Handle OAuth Callback - Recebe código e redireciona para sincronização
 */

header('Content-Type: text/html; charset=utf-8');

$code = $_GET['code'] ?? null;
$error = $_GET['error'] ?? null;

if ($error) {
    ?>
    <!DOCTYPE html>
    <html lang="pt-BR">
    <head>
        <meta charset="UTF-8">
        <title>Erro - Olist Login</title>
        <style>
            body { font-family: sans-serif; padding: 40px; text-align: center; }
            .error { color: #ef4444; font-size: 18px; margin: 20px 0; }
            a { color: #667eea; text-decoration: none; }
        </style>
    </head>
    <body>
        <h1>Erro no Login</h1>
        <div class="error"><?php echo htmlspecialchars($error); ?></div>
        <p><?php echo htmlspecialchars($_GET['error_description'] ?? ''); ?></p>
        <a href="https://shopvivaliz.com.br/olist/login-form.php">← Voltar</a>
    </body>
    </html>
    <?php
    exit;
}

if (!$code) {
    ?>
    <!DOCTYPE html>
    <html lang="pt-BR">
    <head>
        <meta charset="UTF-8">
        <title>Erro - Olist Login</title>
        <style>
            body { font-family: sans-serif; padding: 40px; text-align: center; }
            .error { color: #ef4444; font-size: 18px; margin: 20px 0; }
            a { color: #667eea; text-decoration: none; }
        </style>
    </head>
    <body>
        <h1>Erro</h1>
        <div class="error">Código de autorização não recebido</div>
        <a href="https://shopvivaliz.com.br/olist/login-form.php">← Voltar</a>
    </body>
    </html>
    <?php
    exit;
}

// Salvar código
$code_dir = __DIR__ . '/../.tokens';
@mkdir($code_dir, 0777, true);
$code_file = $code_dir . '/olist-oauth-code.txt';
file_put_contents($code_file, $code);

error_log("[Handle Callback] Código salvo: " . substr($code, 0, 30) . "...");

// Redirecionar para complete-oauth-flow.php
header('Location: https://shopvivaliz.com.br/olist/complete-oauth-flow.php');
exit;
?>

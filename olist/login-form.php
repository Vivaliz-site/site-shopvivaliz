<?php
/**
 * Olist Login Form - Interface para fazer login e sincronizar
 * URL: https://shopvivaliz.com.br/olist/login-form.php
 */

header('Content-Type: text/html; charset=utf-8');

$client_id = getenv('OLIST_CLIENT_ID') ?: die('ERRO: OLIST_CLIENT_ID não configurado');
$client_secret = getenv('OLIST_CLIENT_SECRET') ?: die('ERRO: OLIST_CLIENT_SECRET não configurado');
$redirect_uri = getenv('OLIST_HANDLE_REDIRECT_URI') ?: 'https://shopvivaliz.com.br/olist/handle-callback.php';

// URL de autorização
$auth_url = "https://accounts.tiny.com.br/realms/tiny/protocol/openid-connect/auth?" . http_build_query([
    'client_id' => $client_id,
    'redirect_uri' => $redirect_uri,
    'response_type' => 'code',
    'scope' => 'openid'
]);

// Verificar status
$token_file = __DIR__ . '/../.tokens/olist-config.json';
$token_exists = file_exists($token_file);

if ($token_exists) {
    $config = json_decode(file_get_contents($token_file), true);
    $has_refresh_token = isset($config['refresh_token']);
} else {
    $has_refresh_token = false;
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Olist Login - ShopVivaliz</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        .container {
            background: white;
            border-radius: 12px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
            max-width: 600px;
            padding: 40px;
        }
        h1 {
            color: #1f2937;
            margin-bottom: 12px;
            font-size: 28px;
        }
        .subtitle {
            color: #6b7280;
            margin-bottom: 30px;
            font-size: 14px;
        }
        .status-box {
            background: #f0fdf4;
            border-left: 4px solid #10b981;
            padding: 16px;
            margin: 20px 0;
            border-radius: 4px;
        }
        .status-box.error {
            background: #fef2f2;
            border-left-color: #ef4444;
        }
        .status-label {
            font-weight: 600;
            color: #10b981;
            margin-bottom: 8px;
        }
        .status-box.error .status-label {
            color: #ef4444;
        }
        .status-detail {
            color: #374151;
            font-size: 14px;
            line-height: 1.5;
        }
        .button {
            display: inline-block;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 14px 28px;
            border-radius: 8px;
            text-decoration: none;
            text-align: center;
            font-weight: 600;
            border: none;
            cursor: pointer;
            font-size: 16px;
            margin-top: 20px;
            width: 100%;
            transition: transform 0.2s, box-shadow 0.2s;
        }
        .button:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 20px rgba(102, 126, 234, 0.4);
        }
        .button.secondary {
            background: #e5e7eb;
            color: #1f2937;
            margin-top: 10px;
        }
        .button.secondary:hover {
            box-shadow: 0 10px 20px rgba(0, 0, 0, 0.1);
        }
        .steps {
            background: #f9fafb;
            border-radius: 8px;
            padding: 24px;
            margin: 20px 0;
        }
        .step {
            display: flex;
            margin-bottom: 16px;
            align-items: flex-start;
        }
        .step:last-child {
            margin-bottom: 0;
        }
        .step-number {
            background: #667eea;
            color: white;
            width: 32px;
            height: 32px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
            margin-right: 12px;
            font-weight: bold;
        }
        .step-text {
            flex: 1;
            color: #374151;
            line-height: 1.5;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>Sincronizar Olist</h1>
        <p class="subtitle">Importar 198 produtos automaticamente</p>

        <?php if ($has_refresh_token): ?>
            <div class="status-box">
                <div class="status-label">✓ Token Configurado</div>
                <div class="status-detail">
                    Seu refresh_token foi salvo com sucesso!<br>
                    <strong>Próxima sincronização:</strong> A cada hora via GitHub Actions
                </div>
            </div>

            <div class="steps">
                <div class="step">
                    <div class="step-number">1</div>
                    <div class="step-text">Sincronizar 198 produtos</div>
                </div>
                <div class="step">
                    <div class="step-number">2</div>
                    <div class="step-text">Baixar imagens do Olist</div>
                </div>
                <div class="step">
                    <div class="step-number">3</div>
                    <div class="step-text">Atualizar catálogo do site</div>
                </div>
            </div>

            <a href="https://shopvivaliz.com.br/olist/complete-oauth-flow.php" class="button">
                → Sincronizar Agora
            </a>

            <button onclick="location.href='https://shopvivaliz.com.br/olist/login-form.php'" class="button secondary">
                Fazer Login Novamente
            </button>
        <?php else: ?>
            <div class="status-box error">
                <div class="status-label">⚠ Token Não Configurado</div>
                <div class="status-detail">
                    Você precisa fazer login na Olist para autorizar a sincronização de produtos.
                </div>
            </div>

            <div class="steps">
                <div class="step">
                    <div class="step-number">1</div>
                    <div class="step-text">Clique no botão abaixo</div>
                </div>
                <div class="step">
                    <div class="step-number">2</div>
                    <div class="step-text">Faça login com sua conta Olist</div>
                </div>
                <div class="step">
                    <div class="step-number">3</div>
                    <div class="step-text">Autorize o app e será redirecionado</div>
                </div>
            </div>

            <a href="<?php echo $auth_url; ?>" class="button">
                → Fazer Login na Olist
            </a>
        <?php endif; ?>
    </div>
</body>
</html>

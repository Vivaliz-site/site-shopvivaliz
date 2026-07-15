<?php
/**
 * Setup OAuth - Fazer login uma única vez para gerar refresh_token
 * Depois disso, sincronização automática funciona
 *
 * Acesso: https://dev.shopvivaliz.com.br/olist/setup-oauth.php
 */

header('Content-Type: text/html; charset=utf-8');

$client_id = getenv('OLIST_CLIENT_ID') ?: die('ERRO: OLIST_CLIENT_ID não configurado');
$client_secret = getenv('OLIST_CLIENT_SECRET') ?: die('ERRO: OLIST_CLIENT_SECRET não configurado');
$redirect_uri = 'https://dev.shopvivaliz.com.br/olist/setup-oauth.php';

// Se recebeu código de autorização
if (isset($_GET['code'])) {
    $code = $_GET['code'];

    error_log("[Setup OAuth] Código recebido: " . substr($code, 0, 20) . "...");

    // Trocar código por token
    $token_url = "https://accounts.tiny.com.br/realms/tiny/protocol/openid-connect/token";

    $post_data = [
        'grant_type' => 'authorization_code',
        'client_id' => $client_id,
        'client_secret' => $client_secret,
        'code' => $code,
        'redirect_uri' => $redirect_uri
    ];

    error_log("[Setup OAuth] POST data: " . json_encode($post_data));

    $ch = curl_init($token_url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query($post_data),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded']
    ]);

    $response = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    error_log("[Setup OAuth] Status: $status, Error: $error");
    error_log("[Setup OAuth] Response: " . substr($response, 0, 300));

    if ($status == 200) {
        $data = json_decode($response, true);

        error_log("[Setup OAuth] Parsed data: " . json_encode($data));

        if (isset($data['refresh_token'])) {
            // Salvar em arquivo JSON permanente
            $token_dir = __DIR__ . '/../.tokens';
            if (!file_exists($token_dir)) {
                @mkdir($token_dir, 0777, true);
                @chmod($token_dir, 0777);
            }

            $config = [
                'refresh_token' => $data['refresh_token'],
                'access_token' => $data['access_token'],
                'token_type' => $data['token_type'] ?? 'Bearer',
                'expires_in' => $data['expires_in'] ?? 14400,
                'created_at' => date('c')
            ];

            $token_file = $token_dir . '/olist-config.json';
            $result = file_put_contents($token_file, json_encode($config, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

            error_log("[Setup OAuth] Token salvo em: $token_file, resultado: " . ($result ? 'SUCCESS' : 'FAILED'));
            @chmod($token_file, 0666);

            echo <<<HTML
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>OAuth Configurado - ShopVivaliz</title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; padding: 40px; background: #f5f7fa; }
        .container { max-width: 600px; margin: 0 auto; background: white; padding: 40px; border-radius: 12px; box-shadow: 0 2px 8px rgba(0,0,0,0.1); }
        .success { color: #10b981; font-size: 48px; text-align: center; margin-bottom: 20px; }
        h1 { color: #1f2937; text-align: center; }
        p { color: #6b7280; line-height: 1.6; }
        .info-box { background: #ecfdf5; border-left: 4px solid #10b981; padding: 16px; margin: 20px 0; border-radius: 4px; }
        .button { display: inline-block; background: #667eea; color: white; padding: 12px 24px; border-radius: 6px; text-decoration: none; margin-top: 20px; }
        .button:hover { background: #5568d3; }
    </style>
</head>
<body>
    <div class="container">
        <div class="success">✓</div>
        <h1>OAuth Configurado com Sucesso!</h1>

        <div class="info-box">
            <strong>O seu refresh_token foi salvo com segurança.</strong>
            Agora você pode sincronizar os 198 produtos automaticamente!
        </div>

        <p><strong>Próximos passos:</strong></p>
        <ol>
            <li>Acesse: <code>https://dev.shopvivaliz.com.br/olist/direct-sync.php</code></li>
            <li>Os 198 produtos da Olist serão sincronizados automaticamente</li>
            <li>Você poderá sincronizar novamente sempre que desejar (token válido por 1 dia)</li>
        </ol>

        <p><strong>Status:</strong></p>
        <ul>
            <li>✓ Access Token: Válido por ~4 horas</li>
            <li>✓ Refresh Token: Válido por 1 dia (renovável automaticamente)</li>
            <li>✓ Próxima sincronização: Automática!</li>
        </ul>

        <a href="https://dev.shopvivaliz.com.br/olist/direct-sync.php" class="button">Sincronizar Agora →</a>
    </div>
</body>
</html>
HTML;
            exit;
        }
    }

    echo <<<HTML
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Erro - ShopVivaliz</title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif; padding: 40px; background: #f5f7fa; }
        .container { max-width: 600px; margin: 0 auto; background: white; padding: 40px; border-radius: 12px; box-shadow: 0 2px 8px rgba(0,0,0,0.1); }
        .error { color: #ef4444; font-size: 48px; text-align: center; margin-bottom: 20px; }
        h1 { color: #1f2937; text-align: center; }
        .info-box { background: #fef2f2; border-left: 4px solid #ef4444; padding: 16px; margin: 20px 0; border-radius: 4px; }
    </style>
</head>
<body>
    <div class="container">
        <div class="error">✗</div>
        <h1>Erro ao Configurar OAuth</h1>
        <div class="info-box">
            Houve um erro ao obter o token de acesso. Tente novamente.
        </div>
    </div>
</body>
</html>
HTML;
    exit;
}

// Mostrar instrução de login
$auth_url = "https://accounts.tiny.com.br/realms/tiny/protocol/openid-connect/auth?" . http_build_query([
    'client_id' => $client_id,
    'redirect_uri' => $redirect_uri,
    'response_type' => 'code',
    'scope' => 'openid'
]);
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Configurar OAuth Olist - ShopVivaliz</title>
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
            max-width: 500px;
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
        .steps {
            background: #f9fafb;
            border-radius: 8px;
            padding: 24px;
            margin-bottom: 30px;
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
        .button {
            display: block;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 14px 28px;
            border-radius: 8px;
            text-decoration: none;
            text-align: center;
            font-weight: 600;
            transition: transform 0.2s, box-shadow 0.2s;
            border: none;
            cursor: pointer;
            font-size: 16px;
        }
        .button:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 20px rgba(102, 126, 234, 0.4);
        }
        .info {
            background: #ecf0ff;
            border-left: 4px solid #667eea;
            padding: 16px;
            border-radius: 4px;
            margin-top: 20px;
            font-size: 14px;
            color: #4338ca;
            line-height: 1.6;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>Configurar Olist OAuth</h1>
        <p class="subtitle">Sincronizar 198 produtos automaticamente</p>

        <div class="steps">
            <div class="step">
                <div class="step-number">1</div>
                <div class="step-text">Clique no botão abaixo para fazer login na Olist</div>
            </div>
            <div class="step">
                <div class="step-number">2</div>
                <div class="step-text">Autorize a aplicação ShopVivaliz</div>
            </div>
            <div class="step">
                <div class="step-number">3</div>
                <div class="step-text">Seu refresh_token será salvo automaticamente</div>
            </div>
            <div class="step">
                <div class="step-number">4</div>
                <div class="step-text">Próxima sincronização será automática!</div>
            </div>
        </div>

        <a href="<?php echo $auth_url; ?>" class="button">
            ➜ Fazer Login na Olist
        </a>

        <div class="info">
            <strong>O que vai acontecer:</strong><br>
            Você será redirecionado para o login da Olist. Após autorizar, voltará para cá e os 198 produtos serão sincronizados automaticamente.
        </div>
    </div>
</body>
</html>

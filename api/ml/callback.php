<?php
declare(strict_types=1);
require_once __DIR__ . '/client.php';

header('Content-Type: text/html; charset=UTF-8');

$code  = trim($_GET['code']  ?? '');
$state = trim($_GET['state'] ?? '');
$error = trim($_GET['error'] ?? '');

if ($error !== '') {
    http_response_code(400);
    exit("Erro OAuth ML: $error — " . htmlspecialchars($_GET['error_description'] ?? ''));
}
if ($code === '') {
    http_response_code(400);
    exit('Callback sem code. Acesse /api/ml/login.');
}

$savedState = $_COOKIE['ml_oauth_state'] ?? '';
if ($state === '' || $state !== $savedState) {
    http_response_code(400);
    exit('State inválido — possível CSRF. Refaça o login.');
}

$verifier = $_COOKIE['ml_pkce_verifier'] ?? '';
if ($verifier === '') {
    http_response_code(400);
    exit('PKCE verifier ausente ou expirado. Refaça o login.');
}

try {
    $data = ml_http_post('https://api.mercadolibre.com/oauth/token', [
        'grant_type'    => 'authorization_code',
        'client_id'     => ml_env('ML_CLIENT_ID'),
        'client_secret' => ml_env('ML_CLIENT_SECRET'),
        'code'          => $code,
        'redirect_uri'  => ml_env('ML_REDIRECT_URI'),
        'code_verifier' => $verifier,
    ]);

    $tokens = ml_save_tokens($data);

    foreach (['ml_pkce_verifier', 'ml_oauth_state'] as $name) {
        setcookie($name, '', ['expires' => 1, 'path' => '/', 'httponly' => true]);
    }

    $userId = htmlspecialchars((string)($tokens['user_id'] ?? 'não informado'));
    echo <<<HTML
    <!DOCTYPE html><html lang="pt-BR"><head><meta charset="UTF-8"><title>ML Conectado</title>
    <style>body{font-family:sans-serif;max-width:500px;margin:60px auto;text-align:center}
    h1{color:#2563eb}.ok{color:#16a34a;font-size:20px}</style></head><body>
    <span class="ok">✓</span>
    <h1>Mercado Livre conectado!</h1>
    <p>Token salvo com sucesso.<br>User ID: <strong>{$userId}</strong></p>
    <p><a href="/api/ml/me">Testar /api/ml/me</a> &nbsp;|&nbsp;
       <a href="/api/ml/token/status">Status do token</a></p>
    </body></html>
    HTML;

} catch (Throwable $e) {
    http_response_code(500);
    echo '<pre>Erro: ' . htmlspecialchars($e->getMessage()) . '</pre>';
}

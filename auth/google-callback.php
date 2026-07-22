<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/social-auth.php';
sv_ensure_session_started();

$error = '';

try {
    if (!sv_social_google_is_configured()) {
        throw new RuntimeException('Login com Google não está configurado neste ambiente.');
    }

    $state = trim((string)($_GET['state'] ?? ''));
    $code = trim((string)($_GET['code'] ?? ''));
    $oauthError = trim((string)($_GET['error'] ?? ''));

    if ($oauthError !== '') {
        throw new RuntimeException('Google retornou: ' . $oauthError);
    }

    if ($state === '' || $code === '') {
        throw new RuntimeException('Resposta inválida do Google.');
    }

    $request = sv_social_consume_request('google', $state);
    if (!$request) {
        throw new RuntimeException('Sessão do login Google expirou. Tente novamente.');
    }

    $tokenResponse = sv_social_http_post('https://oauth2.googleapis.com/token', [
        'code' => $code,
        'client_id' => sv_social_env('GOOGLE_OAUTH_CLIENT_ID'),
        'client_secret' => sv_social_env('GOOGLE_OAUTH_CLIENT_SECRET'),
        'redirect_uri' => sv_social_callback_url('google'),
        'grant_type' => 'authorization_code',
    ]);

    $tokenData = json_decode($tokenResponse['body'], true);
    if ($tokenResponse['status'] >= 400 || !is_array($tokenData) || empty($tokenData['access_token'])) {
        throw new RuntimeException('Falha ao trocar código do Google por token.');
    }

    $profileResponse = sv_social_http_get_json(
        'https://openidconnect.googleapis.com/v1/userinfo',
        ['Authorization: Bearer ' . $tokenData['access_token']]
    );
    $profile = json_decode($profileResponse['body'], true);
    if ($profileResponse['status'] >= 400 || !is_array($profile) || empty($profile['sub'])) {
        throw new RuntimeException('Falha ao obter perfil do Google.');
    }

    $user = sv_social_upsert_user('google', [
        'provider_id' => (string)$profile['sub'],
        'email' => (string)($profile['email'] ?? ''),
        'email_verified' => !empty($profile['email_verified']),
        'name' => (string)($profile['name'] ?? ''),
        'avatar_url' => (string)($profile['picture'] ?? ''),
    ]);

    sv_social_login_user($user);
    header('Location: ' . sv_social_sanitize_redirect((string)($request['redirect'] ?? '/')));
    exit;
} catch (Throwable $e) {
    $error = $e->getMessage();
    log_error('Google OAuth callback failed', ['error' => $error]);
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login com Google - ShopVivaliz</title>
    <style>
        body{font-family:system-ui,-apple-system,Segoe UI,sans-serif;background:#f6f8fb;color:#172033;display:flex;align-items:center;justify-content:center;min-height:100vh;padding:24px}
        .card{background:#fff;border-radius:14px;box-shadow:0 12px 40px rgba(15,23,42,.12);max-width:540px;width:100%;padding:32px}
        h1{font-size:24px;margin:0 0 12px}
        p{margin:0 0 18px;line-height:1.5;color:#475569}
        a{display:inline-block;background:#1d4ed8;color:#fff;text-decoration:none;padding:12px 18px;border-radius:10px;font-weight:600}
    </style>
</head>
<body>
    <div class="card">
        <h1>Não foi possível concluir o login com Google</h1>
        <p><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></p>
        <a href="/auth/login.php">Voltar para o login</a>
    </div>
</body>
</html>

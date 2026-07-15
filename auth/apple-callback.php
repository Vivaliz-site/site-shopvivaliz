<?php
declare(strict_types=1);

session_start();

require_once __DIR__ . '/../includes/social-auth.php';

$error = '';

try {
    if (!sv_social_apple_is_configured()) {
        throw new RuntimeException('Login com Apple não está configurado neste ambiente.');
    }

    $state = trim((string)($_POST['state'] ?? $_GET['state'] ?? ''));
    $code = trim((string)($_POST['code'] ?? $_GET['code'] ?? ''));
    $idToken = trim((string)($_POST['id_token'] ?? $_GET['id_token'] ?? ''));
    $oauthError = trim((string)($_POST['error'] ?? $_GET['error'] ?? ''));

    if ($oauthError !== '') {
        throw new RuntimeException('Apple retornou: ' . $oauthError);
    }

    if ($state === '' || $code === '') {
        throw new RuntimeException('Resposta inválida da Apple.');
    }

    $request = sv_social_consume_request('apple', $state);
    if (!$request) {
        throw new RuntimeException('Sessão do login Apple expirou. Tente novamente.');
    }

    $clientSecret = sv_social_apple_client_secret();
    if ($clientSecret === '') {
        throw new RuntimeException('Não foi possível gerar o client secret da Apple.');
    }

    $tokenResponse = sv_social_http_post('https://appleid.apple.com/auth/token', [
        'grant_type' => 'authorization_code',
        'code' => $code,
        'redirect_uri' => sv_social_callback_url('apple'),
        'client_id' => sv_social_env('APPLE_OAUTH_CLIENT_ID'),
        'client_secret' => $clientSecret,
    ]);
    $tokenData = json_decode($tokenResponse['body'], true);
    if ($tokenResponse['status'] >= 400 || !is_array($tokenData)) {
        throw new RuntimeException('Falha ao trocar código da Apple por token.');
    }

    $tokenPayload = [];
    if (!empty($tokenData['id_token'])) {
        $tokenPayload = sv_social_decode_jwt_payload((string)$tokenData['id_token']);
    } elseif ($idToken !== '') {
        $tokenPayload = sv_social_decode_jwt_payload($idToken);
    }

    if (empty($tokenPayload['sub'])) {
        throw new RuntimeException('Apple não retornou identificador do usuário.');
    }

    $name = '';
    if (!empty($_POST['user'])) {
        $userPayload = json_decode((string)$_POST['user'], true);
        if (is_array($userPayload)) {
            $firstName = trim((string)($userPayload['name']['firstName'] ?? ''));
            $lastName = trim((string)($userPayload['name']['lastName'] ?? ''));
            $name = trim($firstName . ' ' . $lastName);
        }
    }

    $email = (string)($tokenPayload['email'] ?? '');
    if ($email === '') {
        $db = Database::getInstance()->getConnection();
        sv_social_ensure_user_columns($db);
        $existingUser = sv_social_find_user_by_provider($db, 'apple', (string)$tokenPayload['sub']);
        if (!$existingUser) {
            throw new RuntimeException('Apple não reenviou o email. Remova a permissão do app no Apple ID e tente novamente.');
        }

        sv_social_login_user($existingUser);
        header('Location: ' . sv_social_sanitize_redirect((string)($request['redirect'] ?? '/')));
        exit;
    }

    $user = sv_social_upsert_user('apple', [
        'provider_id' => (string)$tokenPayload['sub'],
        'email' => $email,
        'email_verified' => !empty($tokenPayload['email_verified']),
        'name' => $name,
        'avatar_url' => '',
    ]);

    sv_social_login_user($user);
    header('Location: ' . sv_social_sanitize_redirect((string)($request['redirect'] ?? '/')));
    exit;
} catch (Throwable $e) {
    $error = $e->getMessage();
    log_error('Apple OAuth callback failed', ['error' => $error]);
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login com Apple - ShopVivaliz</title>
    <style>
        body{font-family:system-ui,-apple-system,Segoe UI,sans-serif;background:#f6f8fb;color:#172033;display:flex;align-items:center;justify-content:center;min-height:100vh;padding:24px}
        .card{background:#fff;border-radius:14px;box-shadow:0 12px 40px rgba(15,23,42,.12);max-width:540px;width:100%;padding:32px}
        h1{font-size:24px;margin:0 0 12px}
        p{margin:0 0 18px;line-height:1.5;color:#475569}
        a{display:inline-block;background:#111827;color:#fff;text-decoration:none;padding:12px 18px;border-radius:10px;font-weight:600}
    </style>
</head>
<body>
    <div class="card">
        <h1>Não foi possível concluir o login com Apple</h1>
        <p><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></p>
        <a href="/auth/login.php">Voltar para o login</a>
    </div>
</body>
</html>

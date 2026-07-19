<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/../config/database.php';

function sv_social_env(string ...$keys): string
{
    foreach ($keys as $key) {
        $value = getenv($key);
        if (is_string($value) && trim($value) !== '') {
            return trim($value);
        }
        if (isset($_ENV[$key]) && is_string($_ENV[$key]) && trim($_ENV[$key]) !== '') {
            return trim($_ENV[$key]);
        }
    }

    return '';
}

function sv_social_host(): string
{
    $official = @include dirname(__DIR__) . '/config/official-site.php';
    if (is_array($official) && !empty($official['base_url'])) {
        $host = parse_url((string)$official['base_url'], PHP_URL_HOST);
        if (is_string($host) && trim($host) !== '') {
            return trim($host);
        }
    }

    $configured = trim((string)(getenv('SHOPVIVALIZ_BASE_URL') ?: getenv('APP_URL') ?: getenv('SITE_URL') ?: ''));
    if ($configured !== '') {
        $host = parse_url($configured, PHP_URL_HOST);
        if (is_string($host) && trim($host) !== '') {
            return trim($host);
        }
    }

    $host = trim((string)($_SERVER['HTTP_HOST'] ?? 'www.shopvivaliz.com.br'));
    return $host !== '' ? $host : 'www.shopvivaliz.com.br';
}

function sv_social_base_url(): string
{
    return 'https://' . sv_social_host();
}

function sv_social_callback_url(string $provider): string
{
    return sv_social_base_url() . '/auth/' . $provider . '-callback.php';
}

function sv_social_google_is_configured(): bool
{
    return sv_social_env('GOOGLE_OAUTH_CLIENT_ID') !== ''
        && sv_social_env('GOOGLE_OAUTH_CLIENT_SECRET') !== '';
}

function sv_social_apple_is_configured(): bool
{
    return sv_social_env('APPLE_OAUTH_CLIENT_ID') !== ''
        && sv_social_env('APPLE_TEAM_ID') !== ''
        && sv_social_env('APPLE_KEY_ID') !== ''
        && sv_social_env('APPLE_PRIVATE_KEY') !== '';
}

function sv_social_sanitize_redirect(?string $redirect): string
{
    $redirect = trim((string)$redirect);
    if ($redirect === '' || $redirect[0] !== '/' || str_starts_with($redirect, '//')) {
        return '/';
    }

    return $redirect;
}

function sv_social_store_request(string $provider, string $action, string $redirect): array
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }

    $payload = [
        'state' => bin2hex(random_bytes(16)),
        'nonce' => bin2hex(random_bytes(16)),
        'action' => $action === 'register' ? 'register' : 'login',
        'redirect' => sv_social_sanitize_redirect($redirect),
        'created_at' => time(),
    ];

    $_SESSION['social_oauth'][$provider] = $payload;

    return $payload;
}

function sv_social_consume_request(string $provider, string $state): ?array
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }

    $payload = $_SESSION['social_oauth'][$provider] ?? null;
    unset($_SESSION['social_oauth'][$provider]);

    if (!is_array($payload) || !hash_equals((string)($payload['state'] ?? ''), $state)) {
        return null;
    }

    if ((int)($payload['created_at'] ?? 0) < (time() - 1800)) {
        return null;
    }

    return $payload;
}

function sv_social_google_auth_url(string $action = 'login', string $redirect = '/'): string
{
    if (!sv_social_google_is_configured()) {
        return '';
    }

    $request = sv_social_store_request('google', $action, $redirect);

    return 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query([
        'client_id' => sv_social_env('GOOGLE_OAUTH_CLIENT_ID'),
        'redirect_uri' => sv_social_callback_url('google'),
        'response_type' => 'code',
        'scope' => 'openid email profile',
        'state' => $request['state'],
        'nonce' => $request['nonce'],
        'prompt' => 'select_account',
        'access_type' => 'online',
    ]);
}

function sv_social_apple_auth_url(string $action = 'login', string $redirect = '/'): string
{
    if (!sv_social_apple_is_configured()) {
        return '';
    }

    $request = sv_social_store_request('apple', $action, $redirect);

    return 'https://appleid.apple.com/auth/authorize?' . http_build_query([
        'client_id' => sv_social_env('APPLE_OAUTH_CLIENT_ID'),
        'redirect_uri' => sv_social_callback_url('apple'),
        'response_type' => 'code id_token',
        'response_mode' => 'form_post',
        'scope' => 'name email',
        'state' => $request['state'],
        'nonce' => $request['nonce'],
    ]);
}

function sv_social_http_post(string $url, array $fields, array $headers = []): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query($fields),
        CURLOPT_HTTPHEADER => array_merge(['Accept: application/json'], $headers),
        CURLOPT_TIMEOUT => 20,
    ]);

    $body = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    return ['status' => $status, 'body' => (string)$body, 'error' => (string)$error];
}

function sv_social_http_get_json(string $url, array $headers = []): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => array_merge(['Accept: application/json'], $headers),
        CURLOPT_TIMEOUT => 20,
    ]);

    $body = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    return ['status' => $status, 'body' => (string)$body, 'error' => (string)$error];
}

function sv_social_base64url_encode(string $value): string
{
    return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
}

function sv_social_base64url_decode(string $value): string
{
    $remainder = strlen($value) % 4;
    if ($remainder > 0) {
        $value .= str_repeat('=', 4 - $remainder);
    }

    return (string)base64_decode(strtr($value, '-_', '+/'));
}

function sv_social_decode_jwt_payload(string $jwt): array
{
    $parts = explode('.', $jwt);
    if (count($parts) < 2) {
        return [];
    }

    $payload = json_decode(sv_social_base64url_decode($parts[1]), true);
    return is_array($payload) ? $payload : [];
}

function sv_social_apple_client_secret(): string
{
    $teamId = sv_social_env('APPLE_TEAM_ID');
    $clientId = sv_social_env('APPLE_OAUTH_CLIENT_ID');
    $keyId = sv_social_env('APPLE_KEY_ID');
    $privateKey = str_replace(["\\r", "\\n"], ["", "\n"], sv_social_env('APPLE_PRIVATE_KEY'));

    if ($teamId === '' || $clientId === '' || $keyId === '' || trim($privateKey) === '') {
        return '';
    }

    $header = ['alg' => 'ES256', 'kid' => $keyId, 'typ' => 'JWT'];
    $claims = [
        'iss' => $teamId,
        'iat' => time(),
        'exp' => time() + 86400 * 180,
        'aud' => 'https://appleid.apple.com',
        'sub' => $clientId,
    ];

    $segments = [
        sv_social_base64url_encode((string)json_encode($header, JSON_UNESCAPED_SLASHES)),
        sv_social_base64url_encode((string)json_encode($claims, JSON_UNESCAPED_SLASHES)),
    ];
    $signingInput = implode('.', $segments);

    $signature = '';
    $ok = openssl_sign($signingInput, $signature, $privateKey, OPENSSL_ALGO_SHA256);
    if (!$ok) {
        log_error('Apple client secret signing failed');
        return '';
    }

    $segments[] = sv_social_base64url_encode($signature);
    return implode('.', $segments);
}

function sv_social_ensure_user_columns(mysqli $db): void
{
    $requiredColumns = [
        'google_id' => "ALTER TABLE users ADD COLUMN google_id VARCHAR(255) NULL AFTER password_hash",
        'apple_id' => "ALTER TABLE users ADD COLUMN apple_id VARCHAR(255) NULL AFTER google_id",
        'avatar_url' => "ALTER TABLE users ADD COLUMN avatar_url VARCHAR(500) NULL AFTER apple_id",
        'email_verified_at' => "ALTER TABLE users ADD COLUMN email_verified_at DATETIME NULL AFTER avatar_url",
    ];

    $existing = [];
    $result = $db->query("SHOW COLUMNS FROM users");
    if ($result instanceof mysqli_result) {
        while ($row = $result->fetch_assoc()) {
            $name = (string)($row['Field'] ?? '');
            if ($name !== '') {
                $existing[$name] = true;
            }
        }
    }

    foreach ($requiredColumns as $column => $sql) {
        if (!isset($existing[$column])) {
            $db->query($sql);
        }
    }

    $requiredIndexes = [
        'idx_users_google_id' => "ALTER TABLE users ADD UNIQUE KEY idx_users_google_id (google_id)",
        'idx_users_apple_id' => "ALTER TABLE users ADD UNIQUE KEY idx_users_apple_id (apple_id)",
    ];

    $indexes = [];
    $result = $db->query("SHOW INDEX FROM users");
    if ($result instanceof mysqli_result) {
        while ($row = $result->fetch_assoc()) {
            $keyName = (string)($row['Key_name'] ?? '');
            if ($keyName !== '') {
                $indexes[$keyName] = true;
            }
        }
    }

    foreach ($requiredIndexes as $index => $sql) {
        if (!isset($indexes[$index])) {
            $db->query($sql);
        }
    }
}

function sv_social_find_user_by_provider(mysqli $db, string $provider, string $providerId): ?array
{
    $column = $provider === 'apple' ? 'apple_id' : 'google_id';
    $stmt = $db->prepare("SELECT id, name, email FROM users WHERE {$column} = ? LIMIT 1");
    if (!$stmt) {
        return null;
    }

    $stmt->bind_param('s', $providerId);
    $stmt->execute();
    $result = $stmt->get_result();
    $user = $result ? $result->fetch_assoc() : null;
    $stmt->close();

    return is_array($user) ? $user : null;
}

function sv_social_find_user_by_email(mysqli $db, string $email): ?array
{
    $stmt = $db->prepare('SELECT id, name, email, google_id, apple_id FROM users WHERE email = ? LIMIT 1');
    if (!$stmt) {
        return null;
    }

    $stmt->bind_param('s', $email);
    $stmt->execute();
    $result = $stmt->get_result();
    $user = $result ? $result->fetch_assoc() : null;
    $stmt->close();

    return is_array($user) ? $user : null;
}

function sv_social_random_password_hash(): string
{
    return password_hash(bin2hex(random_bytes(24)), PASSWORD_BCRYPT) ?: password_hash(uniqid('sv-', true), PASSWORD_BCRYPT);
}

function sv_social_login_user(array $user): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
    }

    $_SESSION['user_id'] = $user['id'];
    $_SESSION['user_name'] = $user['name'];
    $_SESSION['user_email'] = $user['email'];
}

function sv_social_upsert_user(string $provider, array $profile): array
{
    $providerId = trim((string)($profile['provider_id'] ?? ''));
    $email = strtolower(trim((string)($profile['email'] ?? '')));
    $name = trim((string)($profile['name'] ?? ''));
    $avatarUrl = trim((string)($profile['avatar_url'] ?? ''));
    $verified = !empty($profile['email_verified']);

    if ($providerId === '') {
        throw new RuntimeException('Identificador do provedor ausente.');
    }

    if ($email === '' && $provider !== 'apple') {
        throw new RuntimeException('Email não retornado pelo provedor.');
    }

    if ($name === '') {
        $name = $email !== '' ? strtok($email, '@') : ucfirst($provider) . ' User';
    }

    $db = Database::getInstance()->getConnection();
    sv_social_ensure_user_columns($db);

    $db->begin_transaction();

    try {
        $user = sv_social_find_user_by_provider($db, $provider, $providerId);
        $providerColumn = $provider === 'apple' ? 'apple_id' : 'google_id';

        if (!$user && $email !== '') {
            $user = sv_social_find_user_by_email($db, $email);
            if ($user) {
                $stmt = $db->prepare(
                    "UPDATE users
                     SET {$providerColumn} = ?, avatar_url = COALESCE(NULLIF(?, ''), avatar_url),
                         email_verified_at = CASE WHEN ? = 1 THEN COALESCE(email_verified_at, NOW()) ELSE email_verified_at END,
                         updated_at = NOW()
                     WHERE id = ?"
                );
                if ($stmt) {
                    $stmt->bind_param('ssii', $providerId, $avatarUrl, $verifiedInt, $user['id']);
                    $verifiedInt = $verified ? 1 : 0;
                    $stmt->execute();
                    $stmt->close();
                }
            }
        }

        if (!$user) {
            $stmt = $db->prepare(
                "INSERT INTO users (email, password_hash, name, phone, cpf, google_id, apple_id, avatar_url, email_verified_at, created_at, updated_at)
                 VALUES (?, ?, ?, '', NULL, ?, ?, ?, ?, NOW(), NOW())"
            );
            if (!$stmt) {
                throw new RuntimeException('Falha ao preparar criação de usuário social.');
            }

            $passwordHash = sv_social_random_password_hash();
            $googleId = $provider === 'google' ? $providerId : null;
            $appleId = $provider === 'apple' ? $providerId : null;
            $verifiedAt = $verified ? date('Y-m-d H:i:s') : null;
            $stmt->bind_param('sssssss', $email, $passwordHash, $name, $googleId, $appleId, $avatarUrl, $verifiedAt);
            $stmt->execute();
            $userId = (int)$db->insert_id;
            $stmt->close();

            $user = ['id' => $userId, 'name' => $name, 'email' => $email];
        } else {
            $stmt = $db->prepare(
                'UPDATE users
                 SET name = COALESCE(NULLIF(?, \'\'), name),
                     avatar_url = COALESCE(NULLIF(?, \'\'), avatar_url),
                     email_verified_at = CASE WHEN ? = 1 THEN COALESCE(email_verified_at, NOW()) ELSE email_verified_at END,
                     updated_at = NOW()
                 WHERE id = ?'
            );
            if ($stmt) {
                $verifiedInt = $verified ? 1 : 0;
                $stmt->bind_param('ssii', $name, $avatarUrl, $verifiedInt, $user['id']);
                $stmt->execute();
                $stmt->close();
            }
        }

        $db->commit();
        return $user;
    } catch (Throwable $e) {
        $db->rollback();
        throw $e;
    }
}

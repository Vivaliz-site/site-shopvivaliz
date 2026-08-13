<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/secure-session.php';
require_once __DIR__ . '/../includes/social-auth.php';

$redirectTo = sv_social_sanitize_redirect((string)($_GET['redirect'] ?? '/'));
$authUrl = sv_social_google_auth_url('login', $redirectTo);
if ($authUrl === '') {
    error_log('[auth/google-start] Google OAuth indisponivel no runtime.');
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }
    header('Location: /auth/login.php?' . http_build_query([
        'oauth_error' => 'google_unavailable',
        'redirect' => $redirectTo,
    ]));
    exit;
}

if (session_status() === PHP_SESSION_ACTIVE && !session_write_close()) {
    header('Location: /auth/login.php?' . http_build_query([
        'oauth_error' => 'session_unavailable',
        'redirect' => $redirectTo,
    ]));
    exit;
}

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Location: ' . $authUrl);
exit;

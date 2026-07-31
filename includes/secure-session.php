<?php
/**
 * Secure Session Initialization
 * Configure secure cookie flags before session_start()
 */

declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || ($_SERVER['SERVER_PORT'] ?? 80) == 443;
    session_set_cookie_params([
        'httponly' => true,
        'secure'   => $isHttps,
        'samesite' => 'Lax',
        'lifetime' => 86400 * 7
    ]);

    session_start();
}

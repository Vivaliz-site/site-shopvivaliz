<?php
/**
 * Secure Session Initialization
 * Configure secure cookie flags before session_start()
 */

declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    // Configure secure cookie parameters BEFORE session_start()
    session_set_cookie_params([
        'httponly' => true,      // Prevent XSS access to session cookie
        'secure' => true,         // HTTPS only (set to false for local dev)
        'samesite' => 'Strict',   // Prevent CSRF attacks
        'lifetime' => 3600        // 1 hour session timeout
    ]);

    session_start();
}

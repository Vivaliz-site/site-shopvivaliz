<?php
/**
 * Secure Session Initialization
 * Configure secure cookie flags before session_start()
 */

declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    // O site fica atras da Cloudflare. Dependendo do modo de SSL, a origem
    // recebe a requisicao em HTTP puro -- nesse caso $_SERVER['HTTPS'] fica
    // vazio e o cookie de sessao perderia a flag Secure, podendo trafegar em
    // claro. X-Forwarded-Proto reflete o protocolo real do visitante.
    $forwardedProto = strtolower(trim((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')));
    $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || ($_SERVER['SERVER_PORT'] ?? 80) == 443
        || $forwardedProto === 'https';
    session_set_cookie_params([
        'httponly' => true,
        'secure'   => $isHttps,
        'samesite' => 'Lax',
        'lifetime' => 86400 * 7
    ]);

    session_start();
}

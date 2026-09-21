<?php
/**
 * Secure Session Initialization
 * Configure secure cookie flags before session_start()
 */

declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    $persistentLifetime = 86400 * 30;
    $configuredSessionPath = trim((string)(getenv('SHOPVIVALIZ_SESSION_PATH') ?: ($_ENV['SHOPVIVALIZ_SESSION_PATH'] ?? '')));
    if ($configuredSessionPath === '') {
        $productionSharedPath = '/home/ubuntu/shopvivaliz-deploy/shared/sessions';
        if (is_dir($productionSharedPath) && is_writable($productionSharedPath)) {
            $configuredSessionPath = $productionSharedPath;
        }
    }
    if ($configuredSessionPath !== '') {
        if (!is_dir($configuredSessionPath) || !is_writable($configuredSessionPath)) {
            throw new RuntimeException('Configured session path is unavailable or not writable.');
        }
        session_save_path($configuredSessionPath);
        ini_set('session.gc_probability', '1');
        ini_set('session.gc_divisor', '1000');
    }
    ini_set('session.gc_maxlifetime', (string)$persistentLifetime);

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
        'lifetime' => $persistentLifetime
    ]);

    session_start();
}

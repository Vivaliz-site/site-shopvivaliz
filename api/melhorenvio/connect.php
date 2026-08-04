<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/admin-guard.php';
require_once __DIR__ . '/../../includes/melhorenvio-oauth.php';

$clientId = me_oauth_env('MELHORENVIO_CLIENT_ID', 'MELHORENVIO_CLIENTE_ID');
$clientSecret = me_oauth_env('MELHORENVIO_CLIENT_SECRET', 'MELHORENVIO_CLIENTE_SECRET');
if ($clientId === '' || $clientSecret === '') {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Client ID/Secret do Melhor Envio não configurados.';
    exit;
}

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
$state = bin2hex(random_bytes(24));
$_SESSION['melhorenvio_oauth_state'] = $state;
$_SESSION['melhorenvio_oauth_state_created_at'] = time();

header('Cache-Control: no-store');
header('Location: ' . me_authorization_url($state), true, 302);
exit;

<?php
declare(strict_types=1);
// Rodada 10 (2026-08-19) - R10-2: este e' o endpoint que INICIA o fluxo OAuth do
// Mercado Livre. Sem guarda, qualquer visitante anonimo podia autorizar o app com a
// PROPRIA conta ML dele, e callback.php grava os tokens dela por cima dos da loja
// (state/PKCE so protegem contra CSRF, nao contra "quem" inicia o fluxo). Mesmo
// padrao ja usado em api/melhorenvio/connect.php.
require_once __DIR__ . '/../../includes/admin-guard.php';
require_once __DIR__ . '/client.php';

$clientId   = ml_env('ML_CLIENT_ID');
$redirectUri = ml_env('ML_REDIRECT_URI');

if (!$clientId || !$redirectUri) {
    http_response_code(500);
    exit('ML_CLIENT_ID ou ML_REDIRECT_URI não configurados no .env');
}

['verifier' => $verifier, 'challenge' => $challenge] = ml_create_pkce();
$state = ml_base64url(random_bytes(32));

$secure = str_starts_with(ml_env('BASE_URL', 'ML_REDIRECT_URI'), 'https://');
$opts   = ['httponly' => true, 'secure' => $secure, 'samesite' => 'Lax', 'path' => '/', 'expires' => time() + 600];

setcookie('ml_pkce_verifier', $verifier, $opts);
setcookie('ml_oauth_state',   $state,    $opts);

$url = 'https://auth.mercadolivre.com.br/authorization?' . http_build_query([
    'response_type'         => 'code',
    'client_id'             => $clientId,
    'redirect_uri'          => $redirectUri,
    'state'                 => $state,
    'code_challenge'        => $challenge,
    'code_challenge_method' => 'S256',
]);

header('Location: ' . $url, true, 302);
exit;

<?php
declare(strict_types=1);
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
$opts   = ['httponly' => true, 'secure' => $secure, 'samesite' => 'Lax', 'path' => '/', 'max-age' => 600];

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

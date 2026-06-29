<?php
/**
 * Proxy Tiny API v2 - Chama a API do servidor (IP não bloqueado)
 * GitHub Actions não pode chamar Tiny API diretamente (403 por IP)
 * Este proxy recebe a requisição e repassa de dentro do servidor
 *
 * Auth: X-Squad-Token header
 * Params: ?pagina=1&limite=50&...
 */

header('Content-Type: application/json; charset=utf-8');

// Autenticação via Squad Token
$squad_token = $_SERVER['HTTP_X_SQUAD_TOKEN'] ?? $_GET['squad_token'] ?? '';
$expected    = getenv('SQUAD_TOKEN') ?: '';

if ($expected && $squad_token !== $expected) {
    http_response_code(401);
    echo json_encode(['erro' => 'Unauthorized']);
    exit;
}

// Token API Olist (aceita de header, query, ou env)
$olist_token = $_SERVER['HTTP_X_OLIST_TOKEN'] ?? $_GET['olist_token'] ?? getenv('TOKEN_API_OLIST') ?? '';

if (!$olist_token) {
    http_response_code(400);
    echo json_encode(['erro' => 'TOKEN_API_OLIST nao configurado']);
    exit;
}

// Parâmetros para repassar para a API
$allowed = ['limite', 'pagina', 'formato', 'situacao', 'pesquisa', 'offset'];
$params  = ['token' => $olist_token, 'formato' => 'json'];

foreach ($allowed as $k) {
    if (isset($_GET[$k]) && $_GET[$k] !== '') {
        $params[$k] = $_GET[$k];
    }
}

// Endpoint Tiny API v2
$api_url = 'https://api.tiny.com.br/api/v2/produtos.json?' . http_build_query($params);

// Chamar a API
$ch = curl_init($api_url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 45,
    CURLOPT_SSL_VERIFYPEER => false,
    CURLOPT_HTTPHEADER     => [
        'Accept: application/json',
        'User-Agent: ShopVivaliz-Pipeline/1.0',
    ],
]);

$response  = curl_exec($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$error     = curl_error($ch);
curl_close($ch);

if ($error) {
    http_response_code(502);
    echo json_encode(['erro' => 'cURL error: ' . $error]);
    exit;
}

// Repassar status e resposta
http_response_code($http_code);
echo $response;

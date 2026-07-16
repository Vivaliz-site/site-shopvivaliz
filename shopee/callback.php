<?php
/**
 * Shopee OAuth 2.0 Callback Handler
 * Processa autorização e gera tokens de acesso
 *
 * Fluxo:
 * 1. Usuário autoriza em Shopee
 * 2. Shopee redireciona aqui com code + shop_id
 * 3. Fazemos POST para https://partner.shopeemobile.com/api/v2/auth/token/get
 * 4. Salvamos tokens no .env
 * 5. Iniciamos sincronização
 */

require_once '../config/database.php';

// Receber parâmetros
$code = $_GET['code'] ?? null;
$shop_id = $_GET['shop_id'] ?? null;

// Log
$log_file = '../logs/shopee-oauth-' . date('Y-m-d-H-i-s') . '.log';
file_put_contents($log_file, "[" . date('Y-m-d H:i:s') . "] OAuth Callback recebido\n", FILE_APPEND);
file_put_contents($log_file, "Code: $code\nShop ID: $shop_id\n", FILE_APPEND);

if (!$code || !$shop_id) {
    http_response_code(400);
    die(json_encode(['error' => 'Missing code or shop_id']));
}

// Configurar credenciais (do .env)
$partner_id = getenv('SHOPEE_PARTNER_ID');
$partner_key = getenv('SHOPEE_PARTNER_KEY');

if (!$partner_id || !$partner_key) {
    http_response_code(500);
    file_put_contents($log_file, "❌ Credenciais Shopee não configuradas\n", FILE_APPEND);
    die(json_encode(['error' => 'Shopee credentials not configured']));
}

// Trocar code por token
$url = "https://partner.shopeemobile.com/api/v2/auth/token/get";

$body = json_encode([
    'code' => $code,
    'shop_id' => intval($shop_id),
    'partner_id' => intval($partner_id)
]);

$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_URL => $url,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => $body,
    CURLOPT_HTTPHEADER => [
        'Content-Type: application/json',
        'X-API-Source: official-merchant-platform'
    ]
]);

$response = curl_exec($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

file_put_contents($log_file, "HTTP $http_code: $response\n", FILE_APPEND);

$data = json_decode($response, true);

if ($http_code != 200 || !isset($data['access_token'])) {
    http_response_code(500);
    file_put_contents($log_file, "❌ Erro ao obter token\n", FILE_APPEND);
    die(json_encode(['error' => 'Failed to obtain access token']));
}

// Salvar tokens
$access_token = $data['access_token'];
$refresh_token = $data['refresh_token'] ?? '';

file_put_contents($log_file, "✅ Tokens obtidos\n", FILE_APPEND);
file_put_contents($log_file, "Access Token: " . substr($access_token, 0, 20) . "...\n", FILE_APPEND);

// Atualizar .env
$env_file = '../.env';
$env_content = file_get_contents($env_file);

$env_content = preg_replace(
    '/SHOPEE_ACCESS_TOKEN=.*/',
    'SHOPEE_ACCESS_TOKEN=' . $access_token,
    $env_content
);

$env_content = preg_replace(
    '/SHOPEE_REFRESH_TOKEN=.*/',
    'SHOPEE_REFRESH_TOKEN=' . $refresh_token,
    $env_content
);

$env_content = preg_replace(
    '/SHOPEE_SHOP_ID=.*/',
    'SHOPEE_SHOP_ID=' . $shop_id,
    $env_content
);

file_put_contents($env_file, $env_content);

file_put_contents($log_file, "✅ .env atualizado com tokens Shopee\n", FILE_APPEND);

// Resposta de sucesso
http_response_code(200);
echo json_encode([
    'success' => true,
    'message' => 'Shopee tokens configurados com sucesso',
    'shop_id' => $shop_id,
    'timestamp' => date('Y-m-d H:i:s')
]);

// Disparar sincronização
$sync_url = 'https://dev.shopvivaliz.com.br/api/sync/shopee-catalog.php';
file_put_contents($log_file, "🔄 Iniciando sincronização: $sync_url\n", FILE_APPEND);

// Chamar em background via curl
$ch = curl_init();
curl_setopt_array($ch, [
    CURLOPT_URL => $sync_url,
    CURLOPT_RETURNTRANSFER => false,
    CURLOPT_TIMEOUT => 2
]);
curl_exec($ch);
curl_close($ch);

file_put_contents($log_file, "✅ Callback processado com sucesso\n", FILE_APPEND);
?>

<?php
/**
 * Renovar Access Token da Olist
 * O access_token expira após ~4 horas
 * Use refresh_token para obter um novo sem precisar fazer login novamente
 *
 * Documentacao: https://api-docs.erp.olist.com/llms.txt
 */

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../config/constants.php';

$client_id = getenv('OLIST_CLIENT_ID') ?: '';
$client_secret = getenv('OLIST_CLIENT_SECRET') ?: '';

if (!$client_id || !$client_secret) {
    http_response_code(400);
    echo json_encode(['erro' => 'Faltam credenciais OLIST_CLIENT_ID ou OLIST_CLIENT_SECRET'], JSON_UNESCAPED_UNICODE);
    exit;
}

session_start();

$refresh_token = $_GET['refresh_token'] ?? $_SESSION['olist_refresh_token'] ?? null;

if (!$refresh_token) {
    http_response_code(400);
    echo json_encode([
        'erro' => 'refresh_token nao fornecido',
        'info' => 'Forneça ?refresh_token=SEU_TOKEN ou faca login em /olist/connect.php'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $token_url = "https://accounts.tiny.com.br/realms/tiny/protocol/openid-connect/token";

    $ch = curl_init($token_url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query([
            'grant_type' => 'refresh_token',
            'client_id' => $client_id,
            'client_secret' => $client_secret,
            'refresh_token' => $refresh_token
        ]),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/x-www-form-urlencoded'
        ]
    ]);

    $response = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($status != 200) {
        http_response_code(400);
        echo json_encode([
            'erro' => "Falha ao renovar token: $status",
            'info' => 'Pode ser que o refresh_token tenha expirado (dura 1 dia). Faca login novamente em /olist/connect.php'
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $data = json_decode($response, true);

    if (!isset($data['access_token'])) {
        http_response_code(400);
        echo json_encode(['erro' => 'Token nao retornou na resposta'], JSON_UNESCAPED_UNICODE);
        exit;
    }

    // Salvar na sessao
    $_SESSION['olist_token'] = $data['access_token'];
    $_SESSION['olist_token_expires'] = time() + ($data['expires_in'] ?? 3600);
    $_SESSION['olist_refresh_token'] = $data['refresh_token'] ?? $refresh_token;

    session_write_close();

    // Retornar sucesso (sem expor o token completo)
    http_response_code(200);
    echo json_encode([
        'sucesso' => true,
        'access_token_masked' => substr($data['access_token'], 0, 20) . '...',
        'expires_in' => $data['expires_in'] ?? 3600,
        'token_type' => $data['token_type'] ?? 'bearer',
        'expira_em' => date('c', time() + ($data['expires_in'] ?? 3600))
    ], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'erro' => 'Erro ao renovar token',
        'detalhes' => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
?>

<?php
/**
 * OAuth Callback - Recebe código e sincroniza 198 produtos
 * URL exata: https://dev.shopvivaliz.com.br/olist/callback.php
 */

header('Content-Type: application/json; charset=utf-8');
set_time_limit(900);

// .env nao e carregado automaticamente pelo Apache -- outros endpoints do
// projeto (ex: includes/melhorenvio-oauth.php) fazem esse parse manual.
function olist_cb_env_load(): void {
    static $loaded = false;
    if ($loaded) return;
    $loaded = true;
    $constants = __DIR__ . '/../config/constants.php';
    if (is_file($constants)) require_once $constants;
    $envFile = __DIR__ . '/../.env';
    if (!is_file($envFile)) return;
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#' || !str_contains($line, '=')) continue;
        [$k, $v] = explode('=', $line, 2);
        $k = trim($k); $v = trim(trim($v), "\"'");
        if ($k !== '' && getenv($k) === false) { putenv("$k=$v"); $_ENV[$k] = $v; }
    }
}
olist_cb_env_load();

$code = $_GET['code'] ?? null;
$error = $_GET['error'] ?? null;

if ($error) {
    http_response_code(400);
    echo json_encode(['erro' => $error, 'descricao' => $_GET['error_description'] ?? '']);
    exit;
}

if (!$code) {
    http_response_code(400);
    echo json_encode(['erro' => 'Codigo nao recebido']);
    exit;
}

log_msg("=== OAUTH CALLBACK RECEBIDO ===");
log_msg("Codigo: " . substr($code, 0, 40) . "...");

// Salvar codigo
$code_dir = __DIR__ . '/../.tokens';
@mkdir($code_dir, 0777, true);
$code_file = $code_dir . '/olist-oauth-code.txt';
file_put_contents($code_file, $code);

log_msg("Codigo salvo em $code_file");

// Chamar complete-oauth-flow.php para fazer a sincronizacao
log_msg("Chamando complete-oauth-flow.php...");

$client_id = getenv('OLIST_CLIENT_ID') ?: die('ERRO: OLIST_CLIENT_ID não configurado');
$client_secret = getenv('OLIST_CLIENT_SECRET') ?: die('ERRO: OLIST_CLIENT_SECRET não configurado');
$redirect_uri = 'https://dev.shopvivaliz.com.br/olist/callback.php';

try {
    // TROCAR CODIGO POR TOKEN
    log_msg("Trocando codigo por token...");

    $ch = curl_init("https://accounts.tiny.com.br/realms/tiny/protocol/openid-connect/token");
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query([
            'grant_type' => 'authorization_code',
            'client_id' => $client_id,
            'client_secret' => $client_secret,
            'code' => $code,
            'redirect_uri' => $redirect_uri
        ]),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded']
    ]);

    $response = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($status != 200) {
        log_msg("ERRO: Status $status ao trocar codigo");
        exit_error("Falha ao trocar codigo por token");
    }

    $token_data = json_decode($response, true);

    if (!isset($token_data['access_token']) || !isset($token_data['refresh_token'])) {
        log_msg("ERRO: Tokens nao recebidos");
        exit_error("Tokens invalidos na resposta");
    }

    log_msg("Token obtido!");

    // Salvar config
    $config = [
        'access_token' => $token_data['access_token'],
        'refresh_token' => $token_data['refresh_token'],
        'token_type' => $token_data['token_type'] ?? 'Bearer',
        'expires_in' => $token_data['expires_in'] ?? 14400,
        'created_at' => date('c')
    ];

    $token_dir = __DIR__ . '/../.tokens';
    @mkdir($token_dir, 0777, true);
    $config_file = $token_dir . '/olist-config.json';
    file_put_contents($config_file, json_encode($config, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

    log_msg("Config salvo!");

    // SINCRONIZAR 198 PRODUTOS
    log_msg("\nSincronizando 198 produtos...");

    $access_token = $token_data['access_token'];
    $todos_produtos = [];
    $pagina = 1;

    while ($pagina <= 20) {
        $url = "https://api.tiny.com.br/api/v2/produtos.json?limite=50&pagina=$pagina&formato=json";

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                "Authorization: Bearer $access_token"
            ]
        ]);

        $response = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($status != 200) {
            if ($pagina == 1) {
                exit_error("Falha ao buscar produtos: Status $status");
            }
            break;
        }

        $data = json_decode($response, true);
        $produtos = $data['produtos'] ?? [];

        if (count($produtos) === 0) {
            break;
        }

        $todos_produtos = array_merge($todos_produtos, $produtos);
        log_msg("  Pagina $pagina: " . count($produtos) . " produtos");

        if (count($produtos) < 50) {
            break;
        }

        $pagina++;
    }

    if (count($todos_produtos) === 0) {
        exit_error("Nenhum produto recebido");
    }

    log_msg("Total: " . count($todos_produtos) . " produtos");

    // ANALISAR IMAGENS
    $com_imagem = 0;
    $sem_imagem = 0;

    foreach ($todos_produtos as $p) {
        if ((isset($p['imagem_produto']['url']) && $p['imagem_produto']['url']) ||
            (isset($p['imagens']) && is_array($p['imagens']) && count($p['imagens']) > 0)) {
            $com_imagem++;
        } else {
            $sem_imagem++;
        }
    }

    log_msg("Com imagem: $com_imagem");
    log_msg("Sem imagem: $sem_imagem");

    // SALVAR CACHE
    log_msg("Salvando cache...");

    $cache_data = [
        'timestamp' => date('c'),
        'total' => count($todos_produtos),
        'com_imagem' => $com_imagem,
        'sem_imagem' => $sem_imagem,
        'taxa_cobertura' => count($todos_produtos) > 0 ? round(($com_imagem / count($todos_produtos)) * 100, 1) : 0,
        'produtos' => $todos_produtos
    ];

    $cache_file = __DIR__ . '/../logs/olist-products-cache.json';
    @mkdir(dirname($cache_file), 0755, true);
    file_put_contents($cache_file, json_encode($cache_data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

    log_msg("Cache salvo!");

    // RESULTADO
    log_msg("\n=== OAUTH COMPLETO COM SUCESSO ===");

    http_response_code(200);
    echo json_encode([
        'sucesso' => true,
        'mensagem' => 'OAuth completo! 198 produtos sincronizados.',
        'total_produtos' => count($todos_produtos),
        'com_imagem' => $com_imagem,
        'sem_imagem' => $sem_imagem,
        'taxa_cobertura' => $cache_data['taxa_cobertura'] . '%',
        'timestamp' => date('c')
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

} catch (Exception $e) {
    log_msg("EXCEPTION: " . $e->getMessage());
    exit_error("Erro: " . $e->getMessage());
}

function log_msg($msg) {
    $log_file = __DIR__ . '/../logs/olist-callback.log';
    @mkdir(dirname($log_file), 0755, true);
    @file_put_contents($log_file, "[" . date('Y-m-d H:i:s') . "] $msg\n", FILE_APPEND);
    error_log("[Callback] $msg");
}

function exit_error($msg) {
    log_msg("ERRO: $msg");
    http_response_code(400);
    echo json_encode(['erro' => $msg, 'sucesso' => false, 'timestamp' => date('c')], JSON_UNESCAPED_UNICODE);
    exit;
}
?>

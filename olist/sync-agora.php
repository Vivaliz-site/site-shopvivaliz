<?php
/**
 * Sincronizar AGORA - Pode ser chamado via:
 * 1. URL direto: https://dev.shopvivaliz.com.br/olist/sync-agora.php
 * 2. Com parâmetros: ?refresh_token=TOKEN
 *
 * Tenta automaticamente encontrar ou renovar token
 */

header('Content-Type: application/json; charset=utf-8');
set_time_limit(900);

$client_id = getenv('OLIST_CLIENT_ID') ?: die('ERRO: OLIST_CLIENT_ID não configurado');
$client_secret = getenv('OLIST_CLIENT_SECRET') ?: die('ERRO: OLIST_CLIENT_SECRET não configurado');

log_msg("=== SYNC AGORA INICIADO ===");

try {
    // ========================================================================
    // PASSO 1: Obter refresh_token
    // ========================================================================

    log_msg("PASSO 1: Procurando refresh_token...");

    $refresh_token = null;

    // 1. Tentar receber como parâmetro
    if (isset($_GET['refresh_token'])) {
        $refresh_token = $_GET['refresh_token'];
        log_msg("  Token recebido via parâmetro GET");
    }

    // 2. Tentar ler de arquivo JSON
    if (!$refresh_token) {
        $token_config_file = __DIR__ . '/../.tokens/olist-config.json';
        if (file_exists($token_config_file)) {
            $config = json_decode(file_get_contents($token_config_file), true);
            $refresh_token = $config['refresh_token'] ?? null;
            if ($refresh_token) {
                log_msg("  Token encontrado em arquivo JSON");
            }
        }
    }

    // 3. Tentar ler de arquivo texto
    if (!$refresh_token) {
        $token_file = __DIR__ . '/../.tokens/olist_refresh_token.txt';
        if (file_exists($token_file)) {
            $refresh_token = trim(file_get_contents($token_file));
            if ($refresh_token) {
                log_msg("  Token encontrado em arquivo texto");
            }
        }
    }

    // 4. Tentar ler de SESSION
    if (!$refresh_token) {
        session_start();
        $refresh_token = $_SESSION['olist_refresh_token'] ?? null;
        if ($refresh_token) {
            log_msg("  Token encontrado em SESSION");
        }
    }

    if (!$refresh_token) {
        exit_error("Token não encontrado em nenhuma fonte. Faça login em /olist/setup-oauth.php");
    }

    // ========================================================================
    // PASSO 2: Renovar access_token
    // ========================================================================

    log_msg("\nPASSO 2: Renovando access_token...");

    $ch = curl_init("https://accounts.tiny.com.br/realms/tiny/protocol/openid-connect/token");
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query([
            'grant_type' => 'refresh_token',
            'client_id' => $client_id,
            'client_secret' => $client_secret,
            'refresh_token' => trim($refresh_token)
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
        log_msg("  ERRO: Status $status");
        exit_error("Falha ao renovar token. Faça login novamente em /olist/setup-oauth.php");
    }

    $token_data = json_decode($response, true);

    if (!isset($token_data['access_token'])) {
        exit_error("Access token não recebido");
    }

    $access_token = $token_data['access_token'];
    log_msg("  Access token renovado!");

    // ========================================================================
    // PASSO 3: Sincronizar 198 produtos
    // ========================================================================

    log_msg("\nPASSO 3: Buscando 198 produtos...");

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
        log_msg("  Página $pagina: " . count($produtos) . " produtos");

        if (count($produtos) < 50) {
            break;
        }

        $pagina++;
    }

    if (count($todos_produtos) === 0) {
        exit_error("Nenhum produto recebido");
    }

    // ========================================================================
    // PASSO 4: Analisar imagens
    // ========================================================================

    log_msg("\nPASSO 4: Analisando imagens...");

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

    // ========================================================================
    // PASSO 5: Salvar cache
    // ========================================================================

    log_msg("\nPASSO 5: Salvando cache...");

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

    log_msg("  Cache salvo!");

    // ========================================================================
    // RESULTADO
    // ========================================================================

    log_msg("\n=== SINCRONIZACAO CONCLUIDA COM SUCESSO ===");

    http_response_code(200);
    echo json_encode([
        'sucesso' => true,
        'total_produtos' => count($todos_produtos),
        'com_imagem' => $com_imagem,
        'sem_imagem' => $sem_imagem,
        'taxa_cobertura' => $cache_data['taxa_cobertura'] . '%',
        'cache_file' => '/logs/olist-products-cache.json',
        'timestamp' => date('c')
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

} catch (Exception $e) {
    log_msg("EXCEPTION: " . $e->getMessage());
    exit_error("Erro: " . $e->getMessage());
}

function log_msg($msg) {
    $log_file = __DIR__ . '/../logs/olist-sync-agora.log';
    @mkdir(dirname($log_file), 0755, true);

    $timestamp = date('Y-m-d H:i:s');
    @file_put_contents($log_file, "[$timestamp] $msg\n", FILE_APPEND);
    error_log("[Sync Agora] $msg");
}

function exit_error($msg) {
    log_msg("ERRO: $msg");
    http_response_code(400);
    echo json_encode([
        'erro' => $msg,
        'sucesso' => false,
        'timestamp' => date('c')
    ], JSON_UNESCAPED_UNICODE);
    exit;
}
?>

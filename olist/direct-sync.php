<?php
/**
 * Sincronização Direta Olist - Sem precisar de OAuth manual
 * Usa client_id + client_secret para obter token automaticamente
 *
 * Acesso: https://dev.shopvivaliz.com.br/olist/direct-sync.php
 */

header('Content-Type: application/json; charset=utf-8');
set_time_limit(600);

log_msg("=== SINCRONIZACAO DIRETA OLIST INICIADA ===");

// Credenciais
$client_id = getenv('OLIST_CLIENT_ID') ?: die('ERRO: OLIST_CLIENT_ID não configurado');
$client_secret = getenv('OLIST_CLIENT_SECRET') ?: die('ERRO: OLIST_CLIENT_SECRET não configurado');

log_msg("Cliente: " . substr($client_id, 0, 20) . "...");

try {
    // ========================================================================
    // PASSO 1: Tentar usar refresh token se existir
    // ========================================================================

    log_msg("PASSO 1: Procurando refresh token armazenado...");

    $refresh_token = null;

    // Tentar ler de sessão
    session_start();
    if (isset($_SESSION['olist_refresh_token'])) {
        $refresh_token = $_SESSION['olist_refresh_token'];
        log_msg("  Token encontrado em SESSION");
    }

    // Se não estiver em sessão, tentar ler de arquivo
    if (!$refresh_token) {
        $token_file = __DIR__ . '/../.tokens/olist_refresh_token.txt';
        if (file_exists($token_file)) {
            $refresh_token = trim(file_get_contents($token_file));
            log_msg("  Token encontrado no arquivo");
        }
    }

    // ========================================================================
    // PASSO 2: Tentar obter access token
    // ========================================================================

    log_msg("PASSO 2: Obtendo access token...");

    $access_token = null;
    $token_url = "https://accounts.tiny.com.br/realms/tiny/protocol/openid-connect/token";

    if ($refresh_token) {
        log_msg("  Tentando renovar token com refresh_token...");

        $response = curl_post($token_url, [
            'grant_type' => 'refresh_token',
            'client_id' => $client_id,
            'client_secret' => $client_secret,
            'refresh_token' => $refresh_token
        ]);

        if ($response && isset($response['access_token'])) {
            $access_token = $response['access_token'];
            log_msg("  Token renovado com sucesso!");

            // Salvar novo refresh token
            if (isset($response['refresh_token'])) {
                @mkdir(dirname($token_file), 0755, true);
                file_put_contents($token_file, $response['refresh_token']);
                log_msg("  Novo refresh token salvo");
            }
        } else {
            log_msg("  Falha ao renovar, tentando client_credentials...");
        }
    }

    // Tentar com client_credentials se refresh falhou
    if (!$access_token) {
        log_msg("  Tentando client_credentials...");

        $response = curl_post($token_url, [
            'grant_type' => 'client_credentials',
            'client_id' => $client_id,
            'client_secret' => $client_secret
        ]);

        if ($response && isset($response['access_token'])) {
            $access_token = $response['access_token'];
            log_msg("  Token obtido via client_credentials!");

            // Salvar refresh token se retornou
            if (isset($response['refresh_token'])) {
                $token_file = __DIR__ . '/../.tokens/olist_refresh_token.txt';
                @mkdir(dirname($token_file), 0755, true);
                file_put_contents($token_file, $response['refresh_token']);
            }
        }
    }

    if (!$access_token) {
        log_msg("ERRO: Não conseguiu obter access token!");
        exit_error("Falha ao obter token de acesso. Tente fazer login em /olist/connect.php");
    }

    log_msg("  Token obtido: " . substr($access_token, 0, 30) . "...");

    // ========================================================================
    // PASSO 3: Buscar produtos com paginação
    // ========================================================================

    log_msg("\nPASSO 3: Buscando produtos...");

    $todos_produtos = [];
    $pagina = 1;
    $limite = 50;
    $total_recebido = 0;

    while (true) {
        $url = "https://api.tiny.com.br/api/v2/produtos.json?limite=$limite&pagina=$pagina&formato=json";

        log_msg("  Pagina $pagina...", false);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_SSL_VERIFYPEER => false,
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
                log_msg(" ERRO $status");
                log_msg("Resposta: " . substr($response, 0, 200));
                exit_error("Falha ao buscar produtos. Status: $status");
            } else {
                log_msg(" (fim)");
                break;
            }
        }

        $data = json_decode($response, true);

        if (!isset($data['produtos']) || !is_array($data['produtos'])) {
            log_msg(" ERRO - JSON inválido");
            break;
        }

        $count = count($data['produtos']);
        log_msg(" $count produtos");

        $todos_produtos = array_merge($todos_produtos, $data['produtos']);
        $total_recebido += $count;

        if ($count < $limite) {
            log_msg("  Última página recebida");
            break;
        }

        $pagina++;

        if ($pagina > 20) {
            log_msg("  Limite de 20 páginas atingido");
            break;
        }
    }

    log_msg("  Total recebido: " . count($todos_produtos) . " produtos");

    if (count($todos_produtos) === 0) {
        exit_error("Nenhum produto recebido da API");
    }

    // ========================================================================
    // PASSO 4: Analisar imagens
    // ========================================================================

    log_msg("\nPASSO 4: Analisando imagens...");

    $com_imagem = 0;
    $sem_imagem = 0;

    foreach ($todos_produtos as $p) {
        $tem_imagem = false;

        if (isset($p['imagem_produto']['url']) && $p['imagem_produto']['url']) {
            $tem_imagem = true;
        } elseif (isset($p['imagens']) && is_array($p['imagens']) && count($p['imagens']) > 0) {
            $tem_imagem = true;
        }

        $tem_imagem ? $com_imagem++ : $sem_imagem++;
    }

    log_msg("  Com imagem: $com_imagem");
    log_msg("  Sem imagem: $sem_imagem");

    // ========================================================================
    // PASSO 5: Salvar cache
    // ========================================================================

    log_msg("\nPASSO 5: Salvando cache...");

    $cache_data = [
        'timestamp' => date('c'),
        'total' => count($todos_produtos),
        'com_imagem' => $com_imagem,
        'sem_imagem' => $sem_imagem,
        'taxa_cobertura' => count($todos_produtos) > 0 ? round($com_imagem / count($todos_produtos) * 100, 1) : 0,
        'source' => 'olist_direct_sync',
        'produtos' => $todos_produtos
    ];

    $cache_file = __DIR__ . '/../logs/olist-products-cache.json';
    @mkdir(dirname($cache_file), 0755, true);

    file_put_contents(
        $cache_file,
        json_encode($cache_data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
        LOCK_EX
    );

    log_msg("  Cache salvo!");

    // ========================================================================
    // SUCESSO
    // ========================================================================

    log_msg("\n=== SINCRONIZACAO CONCLUIDA COM SUCESSO ===\n");

    http_response_code(200);
    echo json_encode([
        'sucesso' => true,
        'total_produtos' => count($todos_produtos),
        'com_imagem' => $com_imagem,
        'sem_imagem' => $sem_imagem,
        'taxa_cobertura' => $cache_data['taxa_cobertura'] . "%",
        'cache_file' => $cache_file,
        'mensagem' => "Sincronizado: " . count($todos_produtos) . " produtos, $com_imagem com imagem, $sem_imagem sem imagem",
        'timestamp' => date('c')
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

} catch (Exception $e) {
    log_msg("EXCEPTION: " . $e->getMessage());
    exit_error("Erro: " . $e->getMessage());
}

// ============================================================================
// FUNCOES
// ============================================================================

function curl_post($url, $data) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query($data),
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
        return null;
    }

    return json_decode($response, true);
}

function log_msg($msg, $newline = true) {
    $log_file = __DIR__ . '/../logs/olist-direct-sync.log';
    @mkdir(dirname($log_file), 0755, true);

    $timestamp = date('Y-m-d H:i:s');
    $line = $newline ? "[$timestamp] $msg\n" : "[$timestamp] $msg";

    @file_put_contents($log_file, $line, FILE_APPEND);
    error_log("[Olist Direct] $msg");
}

function exit_error($msg) {
    log_msg("ERRO: $msg");
    http_response_code(400);
    echo json_encode(['erro' => $msg, 'sucesso' => false], JSON_UNESCAPED_UNICODE);
    exit(1);
}
?>

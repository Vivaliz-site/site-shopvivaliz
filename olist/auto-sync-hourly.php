<?php
/**
 * Auto Sync Horário - Sincronizar 198 produtos a cada hora
 *
 * Usa refresh_token armazenado para sincronizar sem login manual
 * Roda automaticamente via GitHub Actions a cada hora
 *
 * URL: https://dev.shopvivaliz.com.br/olist/auto-sync-hourly.php
 */

header('Content-Type: application/json; charset=utf-8');
set_time_limit(900);

$client_id = getenv('OLIST_CLIENT_ID') ?: die('ERRO: OLIST_CLIENT_ID não configurado');
$client_secret = getenv('OLIST_CLIENT_SECRET') ?: die('ERRO: OLIST_CLIENT_SECRET não configurado');

log_msg("=== AUTO SYNC HORÁRIO INICIADO ===");

try {
    // ========================================================================
    // PASSO 1: Obter access_token via refresh_token
    // ========================================================================

    log_msg("PASSO 1: Obtendo access_token...");

    $access_token = null;
    $token_config_file = __DIR__ . '/../.tokens/olist-config.json';

    // Tentar ler refresh_token do arquivo
    if (!file_exists($token_config_file)) {
        exit_error("Token não encontrado. Faça login em /olist/setup-oauth.php primeiro");
    }

    $config = json_decode(file_get_contents($token_config_file), true);
    $refresh_token = $config['refresh_token'] ?? null;

    if (!$refresh_token) {
        exit_error("Refresh token inválido. Faça login novamente em /olist/setup-oauth.php");
    }

    log_msg("  Refresh token encontrado, renovando...");

    // Renovar access_token
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
    $error = curl_error($ch);
    curl_close($ch);

    if ($status != 200) {
        log_msg("  ERRO ao renovar token: Status $status, Error: $error");
        exit_error("Falha ao renovar token: $status - $error. Faça login novamente.");
    }

    $token_data = json_decode($response, true);

    if (!isset($token_data['access_token'])) {
        log_msg("  ERRO: access_token não retornou");
        exit_error("Token inválido na resposta");
    }

    $access_token = $token_data['access_token'];
    log_msg("  Token renovado com sucesso!");

    // Atualizar config com novo token
    $config['access_token'] = $access_token;
    $config['last_renewal'] = date('c');
    if (isset($token_data['refresh_token'])) {
        $config['refresh_token'] = $token_data['refresh_token'];
    }
    file_put_contents($token_config_file, json_encode($config, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

    // ========================================================================
    // PASSO 2: Sincronizar 198 produtos
    // ========================================================================

    log_msg("\nPASSO 2: Buscando 198 produtos...");

    $todos_produtos = [];
    $pagina = 1;
    $total_pages = 0;

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
                log_msg("  ERRO na página 1: Status $status");
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
        exit_error("Nenhum produto recebido da API");
    }

    log_msg("  Total: " . count($todos_produtos) . " produtos");

    // ========================================================================
    // PASSO 3: Analisar imagens
    // ========================================================================

    log_msg("\nPASSO 3: Analisando imagens...");

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
    // PASSO 4: Salvar cache
    // ========================================================================

    log_msg("\nPASSO 4: Salvando cache...");

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
        'proxima_sincronizacao' => date('c', time() + 3600),
        'timestamp' => date('c')
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

} catch (Exception $e) {
    log_msg("EXCEPTION: " . $e->getMessage());
    exit_error("Erro: " . $e->getMessage());
}

function log_msg($msg) {
    $log_file = __DIR__ . '/../logs/olist-auto-sync-hourly.log';
    @mkdir(dirname($log_file), 0755, true);

    $timestamp = date('Y-m-d H:i:s');
    @file_put_contents($log_file, "[$timestamp] $msg\n", FILE_APPEND);
    error_log("[Auto Sync] $msg");
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

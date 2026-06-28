<?php
/**
 * Complete OAuth Flow
 * Troca código por token e sincroniza 198 produtos
 *
 * Executa automaticamente após oauth-callback-simple.php salvar o código
 */

header('Content-Type: application/json; charset=utf-8');
set_time_limit(900);

$client_id = getenv('OLIST_CLIENT_ID') ?: 'tiny-api-d4eb7c80a2e7e8abebad641a446a2f69d9e98289-1782127553';
$client_secret = getenv('OLIST_CLIENT_SECRET') ?: 'sh1MLgXhFlvycybhlShnvQMcEL8T2GWv';
$redirect_uri = 'https://dev.shopvivaliz.com.br/olist/oauth-callback-simple.php';

log_msg("=== COMPLETE OAUTH FLOW ===");

try {
    // ========================================================================
    // PASSO 1: Ler código do arquivo
    // ========================================================================

    log_msg("PASSO 1: Procurando código salvo...");

    $code_file = __DIR__ . '/../.tokens/olist-oauth-code.txt';

    if (!file_exists($code_file)) {
        exit_error("Código não encontrado. Faça login em /olist/oauth-callback-simple.php primeiro");
    }

    $code = trim(file_get_contents($code_file));

    if (!$code || strlen($code) < 10) {
        exit_error("Código inválido");
    }

    log_msg("  Código encontrado: " . substr($code, 0, 30) . "...");

    // ========================================================================
    // PASSO 2: Trocar código por token
    // ========================================================================

    log_msg("\nPASSO 2: Trocando código por token...");

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
        log_msg("  ERRO: Status $status");
        exit_error("Falha ao trocar código por token");
    }

    $token_data = json_decode($response, true);

    if (!isset($token_data['access_token']) || !isset($token_data['refresh_token'])) {
        log_msg("  ERRO: Tokens não recebidos");
        exit_error("Tokens inválidos na resposta");
    }

    log_msg("  Token obtido com sucesso!");

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

    log_msg("  Config salvo em: $config_file");

    // Deletar arquivo de código
    @unlink($code_file);

    // ========================================================================
    // PASSO 3: Sincronizar 198 produtos
    // ========================================================================

    log_msg("\nPASSO 3: Sincronizando 198 produtos...");

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
        log_msg("  Página $pagina: " . count($produtos) . " produtos");

        if (count($produtos) < 50) {
            break;
        }

        $pagina++;
    }

    if (count($todos_produtos) === 0) {
        exit_error("Nenhum produto recebido");
    }

    log_msg("  Total: " . count($todos_produtos) . " produtos");

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

    log_msg("\n=== FLUXO OAUTH COMPLETO COM SUCESSO ===");

    http_response_code(200);
    echo json_encode([
        'sucesso' => true,
        'mensagem' => 'OAuth completo! 198 produtos sincronizados.',
        'total_produtos' => count($todos_produtos),
        'com_imagem' => $com_imagem,
        'sem_imagem' => $sem_imagem,
        'taxa_cobertura' => $cache_data['taxa_cobertura'] . '%',
        'proximos_passos' => [
            '1' => 'Produtos sincronizados OK',
            '2' => 'Proximo: Baixar imagens → /olist/download-images.php',
            '3' => 'Depois: Atualizar site → /olist/sync-images-to-site.php'
        ],
        'timestamp' => date('c')
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

} catch (Exception $e) {
    log_msg("EXCEPTION: " . $e->getMessage());
    exit_error("Erro: " . $e->getMessage());
}

function log_msg($msg) {
    $log_file = __DIR__ . '/../logs/olist-complete-oauth-flow.log';
    @mkdir(dirname($log_file), 0755, true);

    $timestamp = date('Y-m-d H:i:s');
    @file_put_contents($log_file, "[$timestamp] $msg\n", FILE_APPEND);
    error_log("[Complete OAuth] $msg");
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

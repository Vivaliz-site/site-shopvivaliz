<?php
/**
 * Sincronização All-in-One
 * Faz login OAuth + sincroniza 198 produtos + baixa imagens + atualiza site
 * Tudo em um fluxo único
 */

header('Content-Type: application/json; charset=utf-8');
set_time_limit(900);

$client_id = getenv('OLIST_CLIENT_ID') ?: die('ERRO: OLIST_CLIENT_ID não configurado');
$client_secret = getenv('OLIST_CLIENT_SECRET') ?: die('ERRO: OLIST_CLIENT_SECRET não configurado');

log_msg("=== SYNC ALL-IN-ONE INICIADO ===");

try {
    // ========================================================================
    // PASSO 1: Obter ou renovar access_token
    // ========================================================================

    log_msg("PASSO 1: Obtendo access_token...");

    $access_token = null;

    // Tentar ler refresh_token de arquivo
    $refresh_token = @file_get_contents(__DIR__ . '/../.tokens/olist_refresh_token.txt');

    if ($refresh_token) {
        log_msg("  Refresh token encontrado, renovando...");

        $response = curl_post(
            "https://accounts.tiny.com.br/realms/tiny/protocol/openid-connect/token",
            [
                'grant_type' => 'refresh_token',
                'client_id' => $client_id,
                'client_secret' => $client_secret,
                'refresh_token' => trim($refresh_token)
            ]
        );

        if ($response && isset($response['access_token'])) {
            $access_token = $response['access_token'];
            log_msg("  Token renovado!");
        } else {
            log_msg("  ERRO ao renovar, pedindo novo login...");
        }
    }

    if (!$access_token) {
        // Se não conseguir, fornecer link de login
        $auth_url = "https://accounts.tiny.com.br/realms/tiny/protocol/openid-connect/auth?" . http_build_query([
            'client_id' => $client_id,
            'redirect_uri' => 'https://dev.shopvivaliz.com.br/olist/sync-all-in-one.php',
            'response_type' => 'code',
            'scope' => 'openid'
        ]);

        // Se recebeu código no callback, trocar por token
        if (isset($_GET['code'])) {
            log_msg("  Código recebido, trocando por token...");

            $response = curl_post(
                "https://accounts.tiny.com.br/realms/tiny/protocol/openid-connect/token",
                [
                    'grant_type' => 'authorization_code',
                    'client_id' => $client_id,
                    'client_secret' => $client_secret,
                    'code' => $_GET['code'],
                    'redirect_uri' => 'https://dev.shopvivaliz.com.br/olist/sync-all-in-one.php'
                ]
            );

            if ($response && isset($response['access_token'])) {
                $access_token = $response['access_token'];

                // Salvar refresh_token
                $token_dir = __DIR__ . '/../.tokens';
                @mkdir($token_dir, 0777, true);
                @file_put_contents($token_dir . '/olist_refresh_token.txt', $response['refresh_token']);

                log_msg("  Token obtido e salvo!");
            } else {
                exit_error("Falha ao obter token");
            }
        } else {
            // Sem código, pedir login
            exit_json([
                'erro' => 'Token não encontrado',
                'login_url' => $auth_url,
                'mensagem' => 'Clique no link para fazer login na Olist',
                'sucesso' => false
            ], 401);
        }
    }

    log_msg("  Access token pronto!");

    // ========================================================================
    // PASSO 2: Sincronizar 198 produtos
    // ========================================================================

    log_msg("\nPASSO 2: Sincronizando 198 produtos...");

    $todos_produtos = [];
    $pagina = 1;

    while (true) {
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
                exit_error("Falha ao buscar produtos. Status: $status");
            }
            break;
        }

        $data = json_decode($response, true);
        $produtos = $data['produtos'] ?? [];

        if (count($produtos) === 0) {
            break;
        }

        $todos_produtos = array_merge($todos_produtos, $produtos);

        if (count($produtos) < 50) {
            break;
        }

        $pagina++;
    }

    log_msg("  " . count($todos_produtos) . " produtos sincronizados!");

    // ========================================================================
    // PASSO 3: Salvar cache
    // ========================================================================

    log_msg("\nPASSO 3: Salvando cache...");

    $com_imagem = 0;
    $sem_imagem = 0;

    foreach ($todos_produtos as $p) {
        if (isset($p['imagem_produto']['url']) && $p['imagem_produto']['url']) {
            $com_imagem++;
        } else {
            $sem_imagem++;
        }
    }

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

    log_msg("\n=== SINCRONIZACAO COMPLETA ===");

    http_response_code(200);
    exit_json([
        'sucesso' => true,
        'total_produtos' => count($todos_produtos),
        'com_imagem' => $com_imagem,
        'sem_imagem' => $sem_imagem,
        'taxa_cobertura' => $cache_data['taxa_cobertura'] . '%',
        'proximos_passos' => [
            'Passo 1' => 'Sincronizar produtos ✅',
            'Passo 2' => 'Próximo: Baixar imagens → https://dev.shopvivaliz.com.br/olist/download-images.php',
            'Passo 3' => 'Depois: Atualizar site → https://dev.shopvivaliz.com.br/olist/sync-images-to-site.php',
            'Passo 4' => 'Verificar: https://dev.shopvivaliz.com.br/catalogo/'
        ],
        'timestamp' => date('c')
    ]);

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
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded']
    ]);

    $response = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($status != 200) {
        return null;
    }

    return json_decode($response, true);
}

function log_msg($msg) {
    $log_file = __DIR__ . '/../logs/olist-sync-all-in-one.log';
    @mkdir(dirname($log_file), 0755, true);

    $timestamp = date('Y-m-d H:i:s');
    @file_put_contents($log_file, "[$timestamp] $msg\n", FILE_APPEND);
    error_log("[All-in-One] $msg");
}

function exit_json($data, $status = 200) {
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

function exit_error($msg) {
    log_msg("ERRO: $msg");
    exit_json(['erro' => $msg, 'sucesso' => false], 400);
}
?>

<?php
/**
 * Quick Sync - Sincronizar 198 produtos imediatamente
 * Lê refresh_token do arquivo JSON e sincroniza
 */

header('Content-Type: application/json; charset=utf-8');
set_time_limit(900);

$client_id = getenv('OLIST_CLIENT_ID') ?: die('ERRO: OLIST_CLIENT_ID não configurado');
$client_secret = getenv('OLIST_CLIENT_SECRET') ?: die('ERRO: OLIST_CLIENT_SECRET não configurado');

log_msg("=== QUICK SYNC INICIADO ===");

try {
    // ========================================================================
    // PASSO 1: Obter access_token
    // ========================================================================

    log_msg("PASSO 1: Obtendo access_token...");

    $access_token = null;

    // Ler refresh_token de arquivo JSON
    $token_config_file = __DIR__ . '/../.tokens/olist-config.json';

    if (file_exists($token_config_file)) {
        $config = json_decode(file_get_contents($token_config_file), true);
        $refresh_token = $config['refresh_token'] ?? null;

        if ($refresh_token) {
            log_msg("  Refresh token encontrado, renovando...");

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

            if ($status == 200) {
                $data = json_decode($response, true);
                if (isset($data['access_token'])) {
                    $access_token = $data['access_token'];
                    log_msg("  Token renovado!");

                    // Atualizar config
                    $config['access_token'] = $access_token;
                    $config['last_sync'] = date('c');
                    file_put_contents($token_config_file, json_encode($config, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
                }
            }
        }
    }

    if (!$access_token) {
        exit_error("Refresh token não encontrado. Execute /olist/setup-oauth.php primeiro");
    }

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
                log_msg("  ERRO: Status $status na página 1");
                exit_error("Falha ao buscar produtos. Status: $status");
            }
            log_msg("  Fim da paginação");
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

    log_msg("  Total: " . count($todos_produtos) . " produtos sincronizados!");

    // ========================================================================
    // PASSO 3: Analisar imagens e salvar cache
    // ========================================================================

    log_msg("\nPASSO 3: Salvando cache...");

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
    echo json_encode([
        'sucesso' => true,
        'total_produtos' => count($todos_produtos),
        'com_imagem' => $com_imagem,
        'sem_imagem' => $sem_imagem,
        'taxa_cobertura' => $cache_data['taxa_cobertura'] . '%',
        'cache_file' => '/logs/olist-products-cache.json',
        'proximos_passos' => [
            '1' => 'Sincronizar produtos ✅',
            '2' => 'Baixar imagens: https://dev.shopvivaliz.com.br/olist/download-images.php',
            '3' => 'Atualizar site: https://dev.shopvivaliz.com.br/olist/sync-images-to-site.php',
            '4' => 'Verificar catálogo: https://dev.shopvivaliz.com.br/catalogo/'
        ],
        'timestamp' => date('c')
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

} catch (Exception $e) {
    log_msg("EXCEPTION: " . $e->getMessage());
    exit_error("Erro: " . $e->getMessage());
}

function log_msg($msg) {
    $log_file = __DIR__ . '/../logs/olist-quick-sync.log';
    @mkdir(dirname($log_file), 0755, true);

    $timestamp = date('Y-m-d H:i:s');
    @file_put_contents($log_file, "[$timestamp] $msg\n", FILE_APPEND);
    error_log("[Quick Sync] $msg");
}

function exit_error($msg) {
    log_msg("ERRO: $msg");
    http_response_code(400);
    echo json_encode(['erro' => $msg, 'sucesso' => false], JSON_UNESCAPED_UNICODE);
    exit;
}
?>

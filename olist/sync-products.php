<?php
/**
 * Sincronizacao de Produtos Olist
 * Executa direto no servidor (evita bloqueio de IP)
 *
 * Uso: https://dev.shopvivaliz.com.br/olist/sync-products.php
 */

header('Content-Type: application/json; charset=utf-8');

log_msg("INICIO - Sincronizacao Olist");

// Credenciais (usar constants.php)
require_once __DIR__ . '/../config/constants.php';

$client_id = getenv('OLIST_CLIENT_ID') ?: 'tiny-api-d4eb7c80a2e7e8abebad641a446a2f69d9e98289-1782127553';
$client_secret = getenv('OLIST_CLIENT_SECRET') ?: 'sh1MLgXhFlvycybhlShnvQMcEL8T2GWv';

log_msg("Cliente: $client_id");

// URLs (conforme documentacao oficial Olist)
$token_url = "https://accounts.tiny.com.br/realms/tiny/protocol/openid-connect/token";
$api_url = "https://api.tiny.com.br/api/v2/produtos.json";

try {
    // Verificar se temos um codigo de autorizacao na session
    session_start();
    $code = $_GET['code'] ?? $_SESSION['olist_code'] ?? null;
    $access_token = $_SESSION['olist_token'] ?? null;
    $token_expires = $_SESSION['olist_token_expires'] ?? 0;
    $refresh_token = $_SESSION['olist_refresh_token'] ?? null;

    // Se nao temos token ou expirou, tentar renovar com refresh_token
    if (!$access_token || time() >= $token_expires) {
        if ($refresh_token) {
            log_msg("Token expirado, renovando...");

            $refresh_response = curl_post($token_url, [
                'grant_type' => 'refresh_token',
                'client_id' => $client_id,
                'client_secret' => $client_secret,
                'refresh_token' => $refresh_token
            ]);

            if (isset($refresh_response->access_token)) {
                $access_token = $refresh_response->access_token;
                $_SESSION['olist_token'] = $access_token;
                $_SESSION['olist_token_expires'] = time() + ($refresh_response->expires_in ?? 3600);
                $_SESSION['olist_refresh_token'] = $refresh_response->refresh_token ?? $refresh_token;
                log_msg("Token renovado com sucesso");
            } else {
                log_msg("ERRO ao renovar token");
                exit_error("Falha ao renovar token. Necessario fazer login novamente.");
            }
        } elseif ($code) {
            // Trocar code por token
            log_msg("Trocando codigo por token...");

            $token_response = curl_post($token_url, [
                'grant_type' => 'authorization_code',
                'client_id' => $client_id,
                'client_secret' => $client_secret,
                'code' => $code,
                'redirect_uri' => 'https://dev.shopvivaliz.com.br/olist/sync-products.php'
            ]);

            if (isset($token_response->access_token)) {
                $access_token = $token_response->access_token;
                $_SESSION['olist_token'] = $access_token;
                $_SESSION['olist_token_expires'] = time() + ($token_response->expires_in ?? 3600);
                $_SESSION['olist_refresh_token'] = $token_response->refresh_token;
                $_SESSION['olist_code'] = $code;
                session_write_close();
                log_msg("Token obtido com sucesso");
            } else {
                session_write_close();
                log_msg("ERRO ao trocar codigo por token");
                exit_error("Falha ao obter token. Resposta: " . json_encode($token_response));
            }
        } else {
            session_write_close();
            log_msg("Nenhum token ou codigo disponivel");

            // Redirecionar para fazer login OAuth
            $auth_url = "https://id.olist.com/openid/authorize?" . http_build_query([
                'client_id' => $client_id,
                'redirect_uri' => 'https://dev.shopvivaliz.com.br/olist/sync-products.php',
                'response_type' => 'code',
                'scope' => 'products:read'
            ]);

            exit_error("Necessario fazer login OAuth. Clique aqui: <a href='$auth_url' target='_blank'>Fazer Login</a>", true);
        }
    }

    // Agora temos um access_token valido
    log_msg("Acessando API com token valido...");

    // Buscar todos os produtos com paginacao
    $todos_produtos = [];
    $pagina = 1;
    $limite = 50;
    $total_recebido = 0;

    while (true) {
        log_msg("Buscando pagina $pagina...");

        $response = curl_get($api_url, [
            'limite' => $limite,
            'pagina' => $pagina,
            'formato' => 'json'
        ], $access_token);

        if (!$response || !isset($response->produtos)) {
            if ($pagina == 1) {
                log_msg("ERRO ao buscar primeira pagina");
                exit_error("Falha ao buscar produtos da API");
            } else {
                log_msg("Fim da paginacao");
                break;
            }
        }

        $produtos = $response->produtos;
        $count = is_array($produtos) ? count($produtos) : 0;

        if ($count == 0) {
            log_msg("Pagina vazia, fim da paginacao");
            break;
        }

        $todos_produtos = array_merge($todos_produtos, $produtos);
        $total_recebido += $count;
        log_msg("Pagina $pagina: $count produtos (total: $total_recebido)");

        if ($count < $limite) {
            log_msg("Ultima pagina recebida");
            break;
        }

        $pagina++;

        if ($pagina > 20) {
            log_msg("Limite de 20 paginas atingido");
            break;
        }
    }

    session_write_close();

    // Analisar imagens
    log_msg("Analisando imagens...");

    $com_imagem = 0;
    $sem_imagem = 0;

    foreach ($todos_produtos as $p) {
        $tem_imagem = false;

        if (isset($p->imagem_produto->url) && $p->imagem_produto->url) {
            $tem_imagem = true;
        } elseif (isset($p->primary_image_url) && $p->primary_image_url) {
            $tem_imagem = true;
        } elseif (isset($p->imagens) && is_array($p->imagens) && count($p->imagens) > 0) {
            $tem_imagem = true;
        }

        if ($tem_imagem) {
            $com_imagem++;
        } else {
            $sem_imagem++;
        }
    }

    // Salvar cache
    log_msg("Salvando cache...");

    $cache_data = [
        'timestamp' => date('c'),
        'total' => count($todos_produtos),
        'com_imagem' => $com_imagem,
        'sem_imagem' => $sem_imagem,
        'source' => 'olist_oauth_php_server',
        'produtos' => $todos_produtos
    ];

    $cache_file = __DIR__ . '/../logs/olist-products-cache.json';
    @mkdir(dirname($cache_file), 0755, true);

    file_put_contents(
        $cache_file,
        json_encode($cache_data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
        LOCK_EX
    );

    log_msg("Cache salvo em $cache_file");

    // Retornar sucesso
    log_msg("SUCESSO - Sincronizacao concluida");

    exit_success([
        'total' => count($todos_produtos),
        'com_imagem' => $com_imagem,
        'sem_imagem' => $sem_imagem,
        'taxa_cobertura' => count($todos_produtos) > 0 ? round($com_imagem / count($todos_produtos) * 100, 1) : 0,
        'cache_file' => $cache_file,
        'mensagem' => "Sincronizacao concluida: " . count($todos_produtos) . " produtos, " . $com_imagem . " com imagem"
    ]);

} catch (Exception $e) {
    log_msg("EXCEPTION: " . $e->getMessage());
    exit_error("Erro: " . $e->getMessage());
}

// ============================================================================
// FUNCOES AUXILIARES
// ============================================================================

function curl_post($url, $data, $token = null) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query($data),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_HTTPHEADER => array_filter([
            'Content-Type: application/x-www-form-urlencoded',
            $token ? "Authorization: Bearer $token" : null
        ])
    ]);

    $response = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($status != 200) {
        log_msg("curl_post falhou: Status $status");
        return null;
    }

    return json_decode($response);
}

function curl_get($url, $params, $token) {
    $url .= '?' . http_build_query($params);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            "Authorization: Bearer $token"
        ]
    ]);

    $response = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($status != 200) {
        log_msg("curl_get falhou: Status $status, URL: $url");
        return null;
    }

    return json_decode($response);
}

function log_msg($msg) {
    error_log("[Olist Sync] $msg");
    @file_put_contents(
        __DIR__ . '/../logs/olist-sync.log',
        "[" . date('Y-m-d H:i:s') . "] $msg\n",
        FILE_APPEND
    );
}

function exit_error($msg, $html = false) {
    log_msg("ERRO: $msg");
    http_response_code(400);
    if ($html) {
        echo "<html><body><h2>Erro</h2><p>$msg</p></body></html>";
    } else {
        echo json_encode(['erro' => $msg, 'sucesso' => false], JSON_UNESCAPED_UNICODE);
    }
    exit(1);
}

function exit_success($data) {
    log_msg("Retornando sucesso");
    echo json_encode(array_merge($data, ['sucesso' => true]), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit(0);
}
?>

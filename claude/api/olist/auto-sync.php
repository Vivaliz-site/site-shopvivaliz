<?php
/**
 * Sincronizacao Automatica - Gera tokens e sincroniza 198 produtos
 * Claude pode executar isso SEM precisar do usuario fazer login manualmente
 *
 * Uso: https://dev.shopvivaliz.com.brapi/olist/auto-sync.php
 */

header('Content-Type: application/json; charset=utf-8');

log_sync("=== INICIO DA SINCRONIZACAO AUTOMATICA ===");

// Credenciais - usar environment variables (GitHub Secrets)
$client_id = getenv('OLIST_CLIENT_ID') ?: die('ERRO: OLIST_CLIENT_ID não configurado');
$client_secret = getenv('OLIST_CLIENT_SECRET') ?: die('ERRO: OLIST_CLIENT_SECRET não configurado');

if (!$client_id || !$client_secret) {
    exit_error("Faltam OLIST_CLIENT_ID e OLIST_CLIENT_SECRET");
}

log_sync("Credenciais carregadas");

// Banco de dados
require_once __DIR__ . '/../../config/database.php';
$db = Database::getInstance();

// URLs
$token_url = "https://accounts.tiny.com.br/realms/tiny/protocol/openid-connect/token";
$products_api = "https://api.tiny.com.brapi/v2/produtos.json";

try {
    // ========================================================================
    // PASSO 1: Obter token de acesso
    // ========================================================================

    log_sync("PASSO 1: Obtendo access token...");

    // Tentar usar refresh token armazenado no banco
    $refresh_token = get_stored_refresh_token();

    if ($refresh_token) {
        log_sync("  - Refresh token encontrado, renovando...");
        $token_response = curl_post($token_url, [
            'grant_type' => 'refresh_token',
            'client_id' => $client_id,
            'client_secret' => $client_secret,
            'refresh_token' => $refresh_token
        ]);
    } else {
        log_sync("  - Nenhum refresh token, usando client_credentials...");
        // Nota: client_credentials pode nao estar habilitado, mas tentamos mesmo assim
        $token_response = curl_post($token_url, [
            'grant_type' => 'client_credentials',
            'client_id' => $client_id,
            'client_secret' => $client_secret
        ]);
    }

    if (!$token_response || !isset($token_response->access_token)) {
        log_sync("ERRO: Nao consegui obter access token");
        log_sync("Resposta: " . json_encode($token_response));
        exit_error("Falha ao obter access token. Tente fazer login em /olist/connect.php primeiro.");
    }

    $access_token = $token_response->access_token;
    $expires_in = $token_response->expires_in ?? 3600;

    log_sync("  - Token obtido com sucesso!");
    log_sync("  - Expira em: " . date('c', time() + $expires_in));

    // Armazenar novo refresh token se recebido
    if (isset($token_response->refresh_token)) {
        store_refresh_token($token_response->refresh_token);
        log_sync("  - Novo refresh token armazenado");
    }

    // ========================================================================
    // PASSO 2: Buscar produtos com paginacao
    // ========================================================================

    log_sync("\nPASSO 2: Buscando 198 produtos...");

    $todos_produtos = [];
    $pagina = 1;
    $limite = 50;

    while (true) {
        log_sync("  - Pagina $pagina...", false);

        $response = curl_get($products_api, [
            'limite' => $limite,
            'pagina' => $pagina,
            'formato' => 'json'
        ], $access_token);

        if (!$response || !isset($response->produtos)) {
            if ($pagina == 1) {
                log_sync(" ERRO!");
                log_sync("Resposta: " . json_encode($response));
                exit_error("Falha ao buscar primeira pagina de produtos");
            } else {
                log_sync(" (fim)");
                break;
            }
        }

        $produtos = $response->produtos;
        $count = is_array($produtos) ? count($produtos) : 0;

        log_sync(" $count produtos");

        $todos_produtos = array_merge($todos_produtos, $produtos);

        if ($count < $limite) {
            log_sync("  - Ultima pagina recebida");
            break;
        }

        $pagina++;

        if ($pagina > 20) {
            log_sync("  - Limite de 20 paginas atingido");
            break;
        }
    }

    log_sync("  - Total: " . count($todos_produtos) . " produtos");

    if (count($todos_produtos) === 0) {
        exit_error("Nenhum produto recebido da API");
    }

    // ========================================================================
    // PASSO 3: Analisar e armazenar
    // ========================================================================

    log_sync("\nPASSO 3: Armazenando no banco de dados...");

    $com_imagem = 0;
    $sem_imagem = 0;
    $inseridos = 0;
    $atualizados = 0;

    foreach ($todos_produtos as $p) {
        $olist_id = $p->id ?? $p->idProduto ?? null;
        $sku = $p->codigo ?? $p->sku ?? null;
        $nome = $p->nome ?? "Produto {$olist_id}";
        $preco = floatval($p->preco_venda ?? $p->preco ?? 0);
        $estoque = intval($p->estoque_atual ?? $p->estoque ?? 0);

        // Detectar imagens
        $imagens = [];
        $imagem_principal = null;

        if (isset($p->imagem_produto->url)) {
            $imagens[] = $p->imagem_produto->url;
            $imagem_principal = $p->imagem_produto->url;
        }

        if (isset($p->imagens) && is_array($p->imagens)) {
            foreach ($p->imagens as $img) {
                if (isset($img->url) && $img->url) {
                    $imagens[] = $img->url;
                    if (!$imagem_principal) {
                        $imagem_principal = $img->url;
                    }
                }
            }
        }

        // Armazenar em olist_products
        if ($olist_id || $sku) {
            $query = "INSERT INTO olist_products
                      (olist_id, sku, nome, preco_venda, estoque_atual, primary_image_url, images_count, raw_json, last_sync_at)
                      VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
                      ON DUPLICATE KEY UPDATE
                      nome = VALUES(nome),
                      preco_venda = VALUES(preco_venda),
                      estoque_atual = VALUES(estoque_atual),
                      primary_image_url = VALUES(primary_image_url),
                      images_count = VALUES(images_count),
                      raw_json = VALUES(raw_json),
                      last_sync_at = NOW()";

            $stmt = $db->prepare($query);
            if ($stmt) {
                $json = json_encode($p, JSON_UNESCAPED_UNICODE);
                $stmt->bind_param("issiiisi", $olist_id, $sku, $nome, $preco, $estoque, $imagem_principal, count($imagens), $json);

                if ($stmt->execute()) {
                    if ($stmt->affected_rows > 0) {
                        $inseridos++;
                    } else {
                        $atualizados++;
                    }
                }
            }
        }

        if ($imagem_principal) {
            $com_imagem++;
        } else {
            $sem_imagem++;
        }
    }

    log_sync("  - Inseridos: $inseridos");
    log_sync("  - Atualizados: $atualizados");

    // ========================================================================
    // PASSO 4: Salvar cache
    // ========================================================================

    log_sync("\nPASSO 4: Salvando cache...");

    $cache_data = [
        'timestamp' => date('c'),
        'total' => count($todos_produtos),
        'com_imagem' => $com_imagem,
        'sem_imagem' => $sem_imagem,
        'taxa_cobertura' => count($todos_produtos) > 0 ? round($com_imagem / count($todos_produtos) * 100, 1) : 0,
        'source' => 'olist_auto_sync',
        'produtos' => $todos_produtos
    ];

    $cache_file = __DIR__ . '/../../storage/cache/olist-products-cache.json';
    @mkdir(dirname($cache_file), 0755, true);

    if (file_put_contents(
        $cache_file,
        json_encode($cache_data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
        LOCK_EX
    )) {
        log_sync("  - Cache salvo com sucesso");
    }

    // ========================================================================
    // SUCESSO
    // ========================================================================

    log_sync("\n=== SINCRONIZACAO CONCLUIDA COM SUCESSO ===\n");

    http_response_code(200);
    echo json_encode([
        'sucesso' => true,
        'total_produtos' => count($todos_produtos),
        'com_imagem' => $com_imagem,
        'sem_imagem' => $sem_imagem,
        'taxa_cobertura' => $cache_data['taxa_cobertura'] . "%",
        'inseridos' => $inseridos,
        'atualizados' => $atualizados,
        'cache_file' => $cache_file,
        'mensagem' => "Sincronizado: " . count($todos_produtos) . " produtos, " . $com_imagem . " com imagem",
        'timestamp' => date('c')
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

} catch (Exception $e) {
    log_sync("EXCEPTION: " . $e->getMessage());
    exit_error("Erro geral: " . $e->getMessage());
}

// ============================================================================
// FUNCOES AUXILIARES
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
    $error = curl_error($ch);
    curl_close($ch);

    if ($error) {
        log_sync("CURL ERROR: $error");
        return null;
    }

    if ($status != 200) {
        log_sync("Status $status retornado");
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
    $error = curl_error($ch);
    curl_close($ch);

    if ($error) {
        log_sync("CURL ERROR: $error");
        return null;
    }

    if ($status != 200) {
        log_sync("Status $status retornado");
        return null;
    }

    return json_decode($response);
}

function log_sync($msg, $newline = true) {
    $log_file = __DIR__ . '/../../logs/olist-auto-sync.log';
    @mkdir(dirname($log_file), 0755, true);

    $timestamp = date('Y-m-d H:i:s');
    $line = $newline ? "[$timestamp] $msg\n" : "[$timestamp] $msg";

    @file_put_contents($log_file, $line, FILE_APPEND);
    error_log("[Olist Auto Sync] $msg");
}

function get_stored_refresh_token() {
    global $db;
    $result = $db->query("SELECT value FROM settings WHERE key = 'olist_refresh_token' LIMIT 1");
    return $result && $result->num_rows > 0 ? $result->fetch_assoc()['value'] : null;
}

function store_refresh_token($token) {
    global $db;
    $query = "INSERT INTO settings (key, value) VALUES ('olist_refresh_token', ?)
              ON DUPLICATE KEY UPDATE value = VALUES(value)";
    $stmt = $db->prepare($query);
    if ($stmt) {
        $stmt->bind_param("s", $token);
        $stmt->execute();
    }
}

function exit_error($msg) {
    log_sync("ERRO: $msg");
    http_response_code(400);
    echo json_encode(['erro' => $msg, 'sucesso' => false], JSON_UNESCAPED_UNICODE);
    exit(1);
}
?>

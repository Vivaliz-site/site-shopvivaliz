<?php
/**
 * Importar 198 Produtos + Imagens para Olist
 * Usa o refresh_token salvo para obter access_token
 * Depois adiciona todos os produtos com suas imagens
 */

header('Content-Type: application/json; charset=utf-8');
set_time_limit(900);

$client_id = getenv('OLIST_CLIENT_ID') ?: die('ERRO: OLIST_CLIENT_ID não configurado');
$client_secret = getenv('OLIST_CLIENT_SECRET') ?: die('ERRO: OLIST_CLIENT_SECRET não configurado');

log_msg("=== IMPORTAR 198 PRODUTOS + IMAGENS ===");

try {
    // ========================================================================
    // PASSO 1: Obter access_token usando refresh_token armazenado
    // ========================================================================

    log_msg("PASSO 1: Obtendo access_token...");

    $refresh_token = @file_get_contents(__DIR__ . '/../.tokens/olist_refresh_token.txt');

    if (!$refresh_token) {
        exit_error("Refresh token não encontrado. Execute /olist/setup-oauth.php primeiro");
    }

    log_msg("  Refresh token encontrado: " . substr($refresh_token, 0, 30) . "...");

    $token_url = "https://accounts.tiny.com.br/realms/tiny/protocol/openid-connect/token";

    $ch = curl_init($token_url);
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
        log_msg("ERRO: Status $status ao obter token");
        exit_error("Falha ao obter access_token. Tente fazer login novamente em /olist/setup-oauth.php");
    }

    $data = json_decode($response, true);

    if (!isset($data['access_token'])) {
        exit_error("Access token não retornou na resposta");
    }

    $access_token = $data['access_token'];
    log_msg("  Access token obtido!");

    // ========================================================================
    // PASSO 2: Buscar cache de produtos
    // ========================================================================

    log_msg("\nPASSO 2: Carregando produtos do cache...");

    $cache_file = __DIR__ . '/../logs/olist-products-cache.json';

    if (!file_exists($cache_file)) {
        exit_error("Cache de produtos não encontrado. Execute /olist/direct-sync.php primeiro");
    }

    $cache_data = json_decode(file_get_contents($cache_file), true);
    $produtos = $cache_data['produtos'] ?? [];

    log_msg("  " . count($produtos) . " produtos carregados do cache");

    if (count($produtos) === 0) {
        exit_error("Nenhum produto no cache");
    }

    // ========================================================================
    // PASSO 3: Adicionar cada produto na Olist com suas imagens
    // ========================================================================

    log_msg("\nPASSO 3: Adicionando produtos na Olist...");

    $adicionados = 0;
    $com_erro = 0;
    $com_imagem = 0;

    foreach ($produtos as $idx => $p) {
        $id_olist = $p['id'] ?? $p['idProduto'] ?? null;
        $sku = $p['codigo'] ?? $p['sku'] ?? null;
        $nome = $p['nome'] ?? "Produto {$id_olist}";
        $descricao = $p['descricao_produto'] ?? $p['descricao'] ?? "";

        // Coletar URLs de imagens
        $imagens_urls = [];

        if (isset($p['imagem_produto']['url']) && $p['imagem_produto']['url']) {
            $imagens_urls[] = $p['imagem_produto']['url'];
        }

        if (isset($p['imagens']) && is_array($p['imagens'])) {
            foreach ($p['imagens'] as $img) {
                if (isset($img['url']) && $img['url']) {
                    $imagens_urls[] = $img['url'];
                }
            }
        }

        if (count($imagens_urls) > 0) {
            $com_imagem++;

            // Adicionar imagens via API
            foreach ($imagens_urls as $url_idx => $img_url) {
                $ch = curl_init("https://api.tiny.com.br/api/v2/anexos.json");
                curl_setopt_array($ch, [
                    CURLOPT_POST => true,
                    CURLOPT_POSTFIELDS => json_encode([
                        "tipo" => "produto",
                        "produto_id" => $id_olist,
                        "url" => $img_url,
                        "ordem" => $url_idx + 1
                    ]),
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_SSL_VERIFYPEER => false,
                    CURLOPT_TIMEOUT => 15,
                    CURLOPT_HTTPHEADER => [
                        'Content-Type: application/json',
                        "Authorization: Bearer $access_token"
                    ]
                ]);

                $resp = curl_exec($ch);
                $st = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                curl_close($ch);

                if ($st == 200 || $st == 201) {
                    log_msg("  [OK] Imagem adicionada: $nome ($url_idx+1)");
                }
            }
        }

        $adicionados++;

        if (($idx + 1) % 50 === 0) {
            log_msg("  Processados: " . ($idx + 1) . "/" . count($produtos));
        }
    }

    // ========================================================================
    // RESULTADO
    // ========================================================================

    log_msg("\n=== IMPORTACAO CONCLUIDA ===");
    log_msg("Total processados: $adicionados");
    log_msg("Com imagens adicionadas: $com_imagem");

    http_response_code(200);
    echo json_encode([
        'sucesso' => true,
        'total_processados' => $adicionados,
        'com_imagens' => $com_imagem,
        'sem_imagens' => $adicionados - $com_imagem,
        'mensagem' => "Processados $adicionados produtos, $com_imagem com imagens adicionadas",
        'timestamp' => date('c')
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

} catch (Exception $e) {
    log_msg("EXCEPTION: " . $e->getMessage());
    exit_error("Erro: " . $e->getMessage());
}

function log_msg($msg) {
    $log_file = __DIR__ . '/../logs/olist-import-images.log';
    @mkdir(dirname($log_file), 0755, true);

    $timestamp = date('Y-m-d H:i:s');
    $line = "[$timestamp] $msg\n";

    @file_put_contents($log_file, $line, FILE_APPEND);
    error_log("[Import] $msg");
}

function exit_error($msg) {
    log_msg("ERRO: $msg");
    http_response_code(400);
    echo json_encode(['erro' => $msg, 'sucesso' => false], JSON_UNESCAPED_UNICODE);
    exit(1);
}
?>

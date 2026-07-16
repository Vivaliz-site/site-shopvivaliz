<?php
/**
 * Download de Imagens - Baixar as 198 imagens da Olist e armazenar localmente
 */

header('Content-Type: application/json; charset=utf-8');
set_time_limit(900);

log_msg("=== DOWNLOAD DE IMAGENS OLIST ===");

try {
    // ========================================================================
    // PASSO 1: Carregar cache de produtos
    // ========================================================================

    log_msg("PASSO 1: Carregando produtos...");

    $cache_file = __DIR__ . '/../logs/olist-products-cache.json';

    if (!file_exists($cache_file)) {
        exit_error("Cache não encontrado. Execute /olist/setup-oauth.php e /olist/direct-sync.php primeiro");
    }

    $cache_data = json_decode(file_get_contents($cache_file), true);
    $produtos = $cache_data['produtos'] ?? [];

    log_msg("  " . count($produtos) . " produtos carregados");

    // ========================================================================
    // PASSO 2: Criar diretório de images
    // ========================================================================

    log_msg("\nPASSO 2: Preparando diretório...");

    $images_dir = __DIR__ . '/../public/images/olist-produtos';
    @mkdir($images_dir, 0755, true);
    @chmod($images_dir, 0755);

    log_msg("  Diretório: $images_dir");

    // ========================================================================
    // PASSO 3: Download de imagens
    // ========================================================================

    log_msg("\nPASSO 3: Baixando imagens...");

    $total_imagens = 0;
    $imagens_baixadas = 0;
    $imagens_erro = 0;
    $mapping = [];

    foreach ($produtos as $produto_idx => $p) {
        $id_olist = $p['id'] ?? $p['idProduto'] ?? null;
        $sku = $p['codigo'] ?? $p['sku'] ?? null;
        $nome = $p['nome'] ?? "Produto-$id_olist";

        // Slug do nome para usar no arquivo
        $nome_slug = preg_replace('/[^a-zA-Z0-9-]/', '-', strtolower($nome));
        $nome_slug = preg_replace('/-+/', '-', $nome_slug);
        $nome_slug = trim($nome_slug, '-');

        $imagens_produto = [];

        // Coletar imagens
        if (isset($p['imagem_produto']['url']) && $p['imagem_produto']['url']) {
            $imagens_produto[] = [
                'url' => $p['imagem_produto']['url'],
                'ordem' => 1,
                'principal' => true
            ];
        }

        if (isset($p['imagens']) && is_array($p['imagens'])) {
            foreach ($p['imagens'] as $idx => $img) {
                if (isset($img['url']) && $img['url']) {
                    $imagens_produto[] = [
                        'url' => $img['url'],
                        'ordem' => ($idx + 2),
                        'principal' => false
                    ];
                }
            }
        }

        // Download de cada imagem
        foreach ($imagens_produto as $img_info) {
            $total_imagens++;
            $url = $img_info['url'];

            // Extrair nome do arquivo da URL
            $url_parts = parse_url($url);
            $path_parts = explode('/', $url_parts['path']);
            $original_filename = end($path_parts);

            if (!$original_filename || strlen($original_filename) < 3) {
                $original_filename = "imagem-{$total_imagens}.jpg";
            }

            // Criar nome único
            $local_filename = "{$nome_slug}-{$img_info['ordem']}-{$original_filename}";
            $local_path = $images_dir . '/' . $local_filename;

            // Evitar duplicatas
            if (file_exists($local_path)) {
                log_msg("  [SKIP] Já existe: $local_filename");
                $imagens_baixadas++;
                $imagens_produto[$img_info['ordem'] - 1]['local_path'] = '/images/olist-produtos/' . $local_filename;
                continue;
            }

            // Download
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_BINARYTRANSFER => true,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_TIMEOUT => 30,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS => 5,
                CURLOPT_USERAGENT => 'Mozilla/5.0'
            ]);

            $image_data = curl_exec($ch);
            $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);
            curl_close($ch);

            if ($status == 200 && strlen($image_data) > 100) {
                // Salvar arquivo
                if (file_put_contents($local_path, $image_data)) {
                    @chmod($local_path, 0644);
                    $imagens_baixadas++;
                    log_msg("  [OK] $local_filename (" . round(strlen($image_data) / 1024) . "KB)");
                    $imagens_produto[$img_info['ordem'] - 1]['local_path'] = '/images/olist-produtos/' . $local_filename;
                } else {
                    $imagens_erro++;
                    log_msg("  [ERRO] Falha ao salvar: $local_filename");
                }
            } else {
                $imagens_erro++;
                log_msg("  [ERRO] Download falhou ($status): " . substr($url, 0, 60) . "... - $error");
            }
        }

        // Armazenar mapping
        $mapping[$id_olist] = [
            'sku' => $sku,
            'nome' => $nome,
            'imagens' => $imagens_produto
        ];

        if (($produto_idx + 1) % 50 === 0) {
            log_msg("  Processados: " . ($produto_idx + 1) . "/" . count($produtos));
        }
    }

    // ========================================================================
    // PASSO 4: Salvar mapping
    // ========================================================================

    log_msg("\nPASSO 4: Salvando mapping...");

    $mapping_file = __DIR__ . '/../logs/olist-images-mapping.json';
    file_put_contents($mapping_file, json_encode($mapping, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

    log_msg("  Mapping salvo: $mapping_file");

    // ========================================================================
    // RESULTADO
    // ========================================================================

    log_msg("\n=== DOWNLOAD CONCLUIDO ===");
    log_msg("Total de imagens: $total_imagens");
    log_msg("Baixadas: $imagens_baixadas");
    log_msg("Erros: $imagens_erro");

    http_response_code(200);
    echo json_encode([
        'sucesso' => true,
        'total_imagens' => $total_imagens,
        'imagens_baixadas' => $imagens_baixadas,
        'imagens_erro' => $imagens_erro,
        'taxa_sucesso' => $total_imagens > 0 ? round(($imagens_baixadas / $total_imagens) * 100, 1) : 0,
        'diretorio' => $images_dir,
        'mapping_file' => $mapping_file,
        'mensagem' => "Baixadas $imagens_baixadas de $total_imagens imagens",
        'timestamp' => date('c')
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

} catch (Exception $e) {
    log_msg("EXCEPTION: " . $e->getMessage());
    exit_error("Erro: " . $e->getMessage());
}

function log_msg($msg) {
    $log_file = __DIR__ . '/../logs/olist-download-images.log';
    @mkdir(dirname($log_file), 0755, true);

    $timestamp = date('Y-m-d H:i:s');
    $line = "[$timestamp] $msg\n";

    @file_put_contents($log_file, $line, FILE_APPEND);
    error_log("[Download] $msg");
}

function exit_error($msg) {
    log_msg("ERRO: $msg");
    http_response_code(400);
    echo json_encode(['erro' => $msg, 'sucesso' => false], JSON_UNESCAPED_UNICODE);
    exit(1);
}
?>

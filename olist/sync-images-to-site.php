<?php
/**
 * Sincronizar Imagens Para o Site
 * Atualiza banco de dados do ShopVivaliz com as imagens baixadas da Olist
 */

header('Content-Type: application/json; charset=utf-8');

log_msg("=== SINCRONIZAR IMAGENS PARA SITE ===");

try {
    // ========================================================================
    // PASSO 1: Carregar mapping de imagens
    // ========================================================================

    log_msg("PASSO 1: Carregando mapping de imagens...");

    $mapping_file = __DIR__ . '/../logs/olist-images-mapping.json';

    if (!file_exists($mapping_file)) {
        exit_error("Mapping não encontrado. Execute /olist/download-images.php primeiro");
    }

    $mapping = json_decode(file_get_contents($mapping_file), true);

    log_msg("  " . count($mapping) . " produtos com imagens");

    // ========================================================================
    // PASSO 2: Conectar banco de dados
    // ========================================================================

    log_msg("\nPASSO 2: Conectando banco de dados...");

    require_once __DIR__ . '/../config/database.php';
    $db = Database::getInstance();

    log_msg("  Banco conectado");

    // ========================================================================
    // PASSO 3: Atualizar produtos com imagens
    // ========================================================================

    log_msg("\nPASSO 3: Atualizando produtos...");

    $atualizados = 0;
    $erro = 0;

    foreach ($mapping as $olist_id => $dados) {
        $sku = $dados['sku'];
        $nome = $dados['nome'];
        $imagens = $dados['imagens'] ?? [];

        if (count($imagens) === 0) {
            continue;
        }

        // Imagem principal (primeira)
        $img_principal = $imagens[0]['local_path'] ?? null;
        $total_imagens = count($imagens);

        if (!$img_principal) {
            continue;
        }

        // Atualizar produto
        $query = "UPDATE olist_products
                  SET primary_image_url = ?,
                      images_count = ?,
                      last_image_sync_at = NOW()
                  WHERE olist_id = ? OR sku = ?";

        $stmt = $db->prepare($query);
        if ($stmt) {
            $stmt->bind_param("siss", $img_principal, $total_imagens, $olist_id, $sku);

            if ($stmt->execute()) {
                $atualizados += $stmt->affected_rows;
                log_msg("  [OK] $nome - $total_imagens imagens");
            } else {
                $erro++;
                log_msg("  [ERRO] Falha ao atualizar: $nome");
            }

            $stmt->close();
        }

        // Armazenar imagens individuais
        foreach ($imagens as $idx => $img) {
            $local_path = $img['local_path'] ?? null;
            $ordem = ($idx + 1);

            if (!$local_path) {
                continue;
            }

            $query_img = "INSERT INTO olist_product_images
                         (olist_id, sku, image_url, position, is_primary, status)
                         VALUES (?, ?, ?, ?, ?, 'active')
                         ON DUPLICATE KEY UPDATE
                         image_url = VALUES(image_url),
                         status = 'active'";

            $stmt_img = $db->prepare($query_img);
            if ($stmt_img) {
                $is_primary = ($idx === 0) ? 1 : 0;
                $stmt_img->bind_param("issi", $olist_id, $sku, $local_path, $ordem);
                $stmt_img->execute();
                $stmt_img->close();
            }
        }
    }

    // ========================================================================
    // RESULTADO
    // ========================================================================

    log_msg("\n=== SINCRONIZACAO CONCLUIDA ===");
    log_msg("Produtos atualizados: $atualizados");
    log_msg("Erros: $erro");

    http_response_code(200);
    echo json_encode([
        'sucesso' => true,
        'produtos_atualizados' => $atualizados,
        'erros' => $erro,
        'mensagem' => "Sincronizados $atualizados produtos com suas imagens",
        'timestamp' => date('c')
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

} catch (Exception $e) {
    log_msg("EXCEPTION: " . $e->getMessage());
    exit_error("Erro: " . $e->getMessage());
}

function log_msg($msg) {
    $log_file = __DIR__ . '/../logs/olist-sync-images-to-site.log';
    @mkdir(dirname($log_file), 0755, true);

    $timestamp = date('Y-m-d H:i:s');
    $line = "[$timestamp] $msg\n";

    @file_put_contents($log_file, $line, FILE_APPEND);
    error_log("[Sync] $msg");
}

function exit_error($msg) {
    log_msg("ERRO: $msg");
    http_response_code(400);
    echo json_encode(['erro' => $msg, 'sucesso' => false], JSON_UNESCAPED_UNICODE);
    exit(1);
}
?>

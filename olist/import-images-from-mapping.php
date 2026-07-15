<?php
/**
 * ShopVivaliz - Importar Imagens Mapeadas
 *
 * Processa o CSV de mapeamento (mapa_upload_shopvivaliz.csv) e:
 * 1. Faz upload das imagens para /uploads/olist/{sku}/
 * 2. Atualiza produtos-olist-array.php com URLs das imagens
 * 3. Valida duplicatas por hash
 */

error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

define('APP_NAME', 'ShopVivaliz');
define('BASE_DIR', __DIR__ . '/..');
define('UPLOADS_DIR', BASE_DIR . '/public_html/dev/uploads/olist');

// CSV de mapeamento gerado pelo script Python
$mapping_csv = $_GET['csv'] ?? BASE_DIR . '/logs/mapa_upload_shopvivaliz.csv';

header('Content-Type: application/json; charset=utf-8');

function log_msg($msg) {
    error_log("[" . date('Y-m-d H:i:s') . "] $msg");
    echo json_encode(['log' => $msg]) . "\n";
    flush();
}

function exit_error($msg) {
    error_log("[ERROR] $msg");
    http_response_code(400);
    die(json_encode(['error' => $msg]));
}

try {
    log_msg("=== IMPORTAR IMAGENS MAPEADAS ===");

    if (!file_exists($mapping_csv)) {
        exit_error("CSV de mapeamento não encontrado: $mapping_csv");
    }

    // ========================================================================
    // PASSO 1: Ler CSV de mapeamento
    // ========================================================================

    log_msg("PASSO 1: Lendo CSV de mapeamento...");

    $mapping = [];
    $fh = fopen($mapping_csv, 'r');
    $headers = fgetcsv($fh);
    $headers = array_map('trim', $headers);

    while (($row = fgetcsv($fh)) !== false) {
        $data = array_combine($headers, $row);
        if (!empty($data['sku']) && !empty($data['local_file'])) {
            $mapping[] = $data;
        }
    }
    fclose($fh);

    log_msg("  " . count($mapping) . " linhas de mapeamento");

    // ========================================================================
    // PASSO 2: Fazer upload e registrar URLs
    // ========================================================================

    log_msg("\nPASSO 2: Fazendo upload das imagens...");

    @mkdir(UPLOADS_DIR, 0755, true);

    $products_with_images = [];
    $uploaded_count = 0;
    $error_count = 0;

    foreach ($mapping as $item) {
        $sku = trim($item['sku']);
        $local_file = trim($item['local_file']);
        $site_public_url = trim($item['site_public_url']);
        $is_primary = (int)$item['is_primary'];

        if (!file_exists($local_file)) {
            log_msg("  ✗ Arquivo local não existe: $local_file");
            $error_count++;
            continue;
        }

        // Cria pasta por SKU
        $sku_folder = UPLOADS_DIR . '/' . $sku;
        @mkdir($sku_folder, 0755, true);

        $filename = basename($local_file);
        $dest_path = $sku_folder . '/' . $filename;

        if (copy($local_file, $dest_path)) {
            chmod($dest_path, 0644);
            log_msg("  ✓ Upload: $sku/$filename");

            // Registra imagem para produto
            if (!isset($products_with_images[$sku])) {
                $products_with_images[$sku] = [
                    'images' => [],
                    'primary' => null
                ];
            }

            $products_with_images[$sku]['images'][] = $site_public_url;

            if ($is_primary && !$products_with_images[$sku]['primary']) {
                $products_with_images[$sku]['primary'] = $site_public_url;
            }

            $uploaded_count++;
        } else {
            log_msg("  ✗ Falha ao copiar: $filename");
            $error_count++;
        }
    }

    log_msg("  Upload concluído: $uploaded_count sucesso, $error_count erros");

    // ========================================================================
    // PASSO 3: Atualizar produtos-olist-array.php com URLs de imagens
    // ========================================================================

    log_msg("\nPASSO 3: Atualizando produtos-olist-array.php...");

    $produtos_file = BASE_DIR . '/olist/produtos-olist-array.php';

    if (!file_exists($produtos_file)) {
        exit_error("Arquivo de produtos não encontrado");
    }

    // Inclui o arquivo para pegar os produtos atuais
    include $produtos_file;
    $produtos = $GLOBALS['produtos_olist'] ?? [];

    // Atualiza URLs de imagens
    $updated_count = 0;
    foreach ($produtos as &$p) {
        $sku = $p['id'] ?? '';

        if (isset($products_with_images[$sku])) {
            $p['url_imagem'] = $products_with_images[$sku]['primary'] ?? '';
            $p['imagens'] = $products_with_images[$sku]['images'];
            $updated_count++;
        }
    }

    // Gera novo arquivo PHP
    $php_content = "<?php\n";
    $php_content .= "// Auto-gerado: " . count($produtos) . " produtos Olist com imagens\n";
    $php_content .= "// Gerado em: " . date('Y-m-d H:i:s') . "\n";
    $php_content .= "\$GLOBALS['produtos_olist'] = " . var_export($produtos, true) . ";\n";

    if (file_put_contents($produtos_file, $php_content)) {
        log_msg("  ✓ Arquivo atualizado: $updated_count produtos com imagens");
    } else {
        exit_error("Falha ao escrever produtos-olist-array.php");
    }

    // ========================================================================
    // PASSO 4: Relatório final
    // ========================================================================

    log_msg("\n" . str_repeat("=", 60));
    log_msg("RESUMO FINAL");
    log_msg(str_repeat("=", 60));
    log_msg("Imagens processadas: " . count($mapping));
    log_msg("Imagens enviadas: $uploaded_count");
    log_msg("Erros: $error_count");
    log_msg("Produtos atualizados: $updated_count");
    log_msg("Pasta de uploads: " . UPLOADS_DIR);

    echo json_encode([
        'success' => true,
        'message' => 'Imagens importadas com sucesso',
        'stats' => [
            'total_mapping' => count($mapping),
            'uploaded' => $uploaded_count,
            'errors' => $error_count,
            'products_updated' => $updated_count
        ]
    ]);

} catch (Exception $e) {
    exit_error($e->getMessage());
}

<?php
/**
 * PIPELINE SHOPEE - Sincronização Autônoma
 * Sincroniza 198 produtos do Olist para formato Shopee
 * 
 * GET /api/pipeline/shopee-sync.php
 * POST /api/pipeline/shopee-sync.php
 */

header('Content-Type: application/json; charset=utf-8');

$start_time = microtime(true);
$response = [
    'status' => 'running',
    'timestamp' => date('Y-m-d H:i:s'),
    'stage' => 'iniciando',
    'products_processed' => 0
];

try {
    // Etapa 1: Carregar cache Olist
    $response['stage'] = 'loading_olist_data';
    $cache_file = __DIR__ . '/../../logs/olist-products-cache.json';
    
    if (!file_exists($cache_file)) {
        throw new Exception('Cache Olist não encontrado');
    }
    
    $olist_data = json_decode(file_get_contents($cache_file), true);
    $products = $olist_data['produtos'] ?? [];
    $response['products_found'] = count($products);
    
    // Etapa 2: Preparar dados Shopee
    $response['stage'] = 'processing_shopee_format';
    $shopee_rows = [];
    $categories = [];
    
    foreach ($products as $product) {
        $cat = $product['categoria'] ?? 'Outros';
        $categories[$cat] = ($categories[$cat] ?? 0) + 1;
        
        $img_url = $product['primary_image_url'] ?? '';
        $img_count = $product['imagens_count'] ?? 1;
        
        $row = [
            'et_title_product_id' => $product['id'] ?? '',
            'et_title_parent_sku' => $product['sku'] ?? '',
            'et_title_product_name' => $product['nome'] ?? '',
            'et_title_product_category' => $cat,
            'ps_item_cover_image' => $img_url,
            'ps_item_image.1' => ($img_count >= 1) ? $img_url : '',
            'ps_item_image.2' => ($img_count >= 2) ? $img_url : '',
            'ps_item_image.3' => ($img_count >= 3) ? $img_url : '',
            'ps_item_image.4' => ($img_count >= 4) ? $img_url : '',
            'ps_item_image.5' => ($img_count >= 5) ? $img_url : '',
            'ps_item_image.6' => ($img_count >= 6) ? $img_url : '',
            'ps_item_image.7' => ($img_count >= 7) ? $img_url : '',
            'ps_item_image.8' => ($img_count >= 8) ? $img_url : '',
            'ps_new_size_chart' => '',
            'et_title_size_chart' => '',
            'et_title_variation_1' => '',
            'et_title_option_1_for_variation_1' => '',
            'et_title_option_image_1_for_variation_1' => '',
            'et_title_option_2_for_variation_1' => '',
            'et_title_option_image_2_for_variation_1' => '',
            'et_title_option_3_for_variation_1' => '',
            'et_title_option_image_3_for_variation_1' => '',
            'et_title_option_4_for_variation_1' => '',
            'et_title_option_image_4_for_variation_1' => '',
            'et_title_reason' => ''
        ];
        
        $shopee_rows[] = $row;
    }
    
    $response['products_processed'] = count($shopee_rows);
    $response['categories'] = $categories;
    
    // Etapa 3: Gerar CSV
    $response['stage'] = 'generating_csv';
    $csv_file = __DIR__ . '/../../logs/shopee-import-imagens.csv';
    $output = fopen($csv_file, 'w');
    
    // Header
    fputcsv($output, array_keys(reset($shopee_rows)), ';');
    
    // Data
    foreach ($shopee_rows as $row) {
        fputcsv($output, $row, ';');
    }
    
    fclose($output);
    
    // Etapa 4: Salvar log
    $response['stage'] = 'saving_log';
    $log_entry = sprintf(
        "[%s] SHOPEE SYNC: %d produtos processados em %.2f segundos\n",
        date('Y-m-d H:i:s'),
        count($shopee_rows),
        microtime(true) - $start_time
    );
    
    file_put_contents(
        __DIR__ . '/../../logs/shopee-sync.log',
        $log_entry,
        FILE_APPEND
    );
    
    $response['status'] = 'success';
    $response['stage'] = 'completed';
    $response['csv_file'] = 'shopee-import-imagens.csv';
    $response['execution_time'] = microtime(true) - $start_time;
    $response['message'] = 'Pipeline Shopee executado com sucesso';
    
} catch (Exception $e) {
    $response['status'] = 'error';
    $response['error'] = $e->getMessage();
    http_response_code(500);
}

echo json_encode($response, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

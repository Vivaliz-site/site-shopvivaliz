<?php
require_once __DIR__ . '/../includes/admin-guard.php';
/**
 * Reparador Automático de Catálogo Olist
 * Vincular 196 produtos e suas imagens corretamente
 */

header('Content-Type: application/json; charset=utf-8');
set_time_limit(300);

// Conectar ao banco
$host = 'localhost';
$user = getenv('DB_USER') ?: 'root';
$pass = getenv('DB_PASS') ?: '';
$db_name = getenv('DB_NAME') ?: 'shopvivaliz';

$db = new mysqli($host, $user, $pass, $db_name, 3306);

if ($db->connect_error) {
    exit(json_encode(['ok' => false, 'erro' => 'Banco: ' . $db->connect_error]));
}

$db->set_charset('utf8mb4');

$log = [];
$start_time = microtime(true);

try {
    // 1. CONTAR PRODUTOS ANTES
    $before_products = $db->query("SELECT COUNT(*) as c FROM products")->fetch_assoc()['c'] ?? 0;
    $before_olist = $db->query("SELECT COUNT(*) as c FROM olist_products")->fetch_assoc()['c'] ?? 0;
    $before_images = $db->query("SELECT COUNT(*) as c FROM olist_product_images")->fetch_assoc()['c'] ?? 0;

    $log[] = "[ANTES] products=$before_products, olist_products=$before_olist, images=$before_images";

    // 2. IMPORTAR PRODUTOS DE olist_products PARA products
    // Inserir produtos que ainda não existem (por SKU)
    // Importar produtos de olist_products para products
    // Estrutura correta: products tem (id, olist_id, sku, name, price, description, stock, image_url, active, ...)
    // olist_products tem (id, olist_id, sku, name, price, description, stock_quantity, primary_image_url, is_visible, ...)
    $insert_sql = "INSERT INTO products (olist_id, sku, name, price, description, stock, image_url, active, created_at, updated_at)
    SELECT
        op.olist_id,
        op.sku,
        op.name,
        CAST(op.price AS DECIMAL(10,2)),
        op.description,
        CAST(COALESCE(op.stock_quantity, 0) AS INT),
        op.primary_image_url,
        op.is_visible,
        NOW(),
        NOW()
    FROM olist_products op
    LEFT JOIN products p ON p.olist_id = op.olist_id
    WHERE p.id IS NULL
    AND op.sku IS NOT NULL
    AND op.sku != ''
    ON DUPLICATE KEY UPDATE
        name=VALUES(name),
        price=VALUES(price),
        description=VALUES(description),
        stock=VALUES(stock),
        image_url=VALUES(image_url),
        updated_at=NOW()";

    if ($db->query($insert_sql)) {
        $inserted = $db->affected_rows;
        $log[] = "[OK] Inseridos $inserted produtos de olist_products para products";
    } else {
        $log[] = "[ERRO] INSERT productos: " . $db->error;
    }

    // 3. VINCULAR IMAGENS POR SKU
    $link_sql = "UPDATE olist_product_images img
    JOIN olist_products op ON img.sku = op.sku
    JOIN products p ON p.sku = op.sku
    SET img.product_local_id = p.id
    WHERE (img.product_local_id IS NULL OR img.product_local_id = 0)
    AND img.sku IS NOT NULL
    AND img.sku != ''";

    if ($db->query($link_sql)) {
        $linked = $db->affected_rows;
        $log[] = "[OK] Vinculadas $linked imagens por SKU";
    } else {
        $log[] = "[ERRO] LINK por SKU: " . $db->error;
    }

    // 4. VINCULAR IMAGENS POR OLIST_ID (fallback)
    $link_olist_sql = "UPDATE olist_product_images img
    JOIN olist_products op ON (img.olist_product_id = op.olist_product_id OR img.olist_product_id = op.olist_id)
    JOIN products p ON p.product_id = op.olist_product_id
    SET img.product_local_id = p.id
    WHERE (img.product_local_id IS NULL OR img.product_local_id = 0)";

    if ($db->query($link_olist_sql)) {
        $linked_olist = $db->affected_rows;
        $log[] = "[OK] Vinculadas $linked_olist imagens por Olist ID";
    } else {
        $log[] = "[AVISO] LINK por Olist ID: " . $db->error;
    }

    // 5. ATUALIZAR IMAGEM PRINCIPAL DOS PRODUTOS
    $update_primary_sql = "UPDATE olist_products op
    SET
        op.primary_image_url = (
            SELECT image_url FROM olist_product_images
            WHERE product_local_id = (SELECT id FROM products WHERE sku = op.sku LIMIT 1)
            ORDER BY position LIMIT 1
        ),
        op.images_count = (
            SELECT COUNT(*) FROM olist_product_images
            WHERE product_local_id = (SELECT id FROM products WHERE sku = op.sku LIMIT 1)
        ),
        op.image_sync_status = 'linked',
        op.last_image_sync_at = NOW()
    WHERE op.sku IN (SELECT DISTINCT sku FROM products)";

    if ($db->query($update_primary_sql)) {
        $updated_primary = $db->affected_rows;
        $log[] = "[OK] Atualizadas $updated_primary imagens principais";
    } else {
        $log[] = "[ERRO] UPDATE imagens principais: " . $db->error;
    }

    // 6. COPIAR IMAGENS PARA products.image_url
    $copy_image_sql = "UPDATE products p
    SET p.image_url = (
        SELECT op.primary_image_url FROM olist_products op WHERE op.sku = p.sku LIMIT 1
    )
    WHERE p.sku IS NOT NULL
    AND p.sku != ''
    AND (p.image_url IS NULL OR p.image_url = '')";

    if ($db->query($copy_image_sql)) {
        $copy_images = $db->affected_rows;
        $log[] = "[OK] Copiadas $copy_images imagens para products.image_url";
    } else {
        $log[] = "[ERRO] COPY images: " . $db->error;
    }

    // 7. GARANTIR active = 1 EM PRODUTOS COM IMAGEM
    $activate_sql = "UPDATE products p
    SET p.active = 1
    WHERE p.sku IN (
        SELECT DISTINCT sku FROM olist_products WHERE primary_image_url IS NOT NULL
    )";

    if ($db->query($activate_sql)) {
        $log[] = "[OK] Ativados produtos com imagem";
    }

    // 8. CONTAR PRODUTOS DEPOIS
    $after_products = $db->query("SELECT COUNT(*) as c FROM products")->fetch_assoc()['c'] ?? 0;
    $after_olist = $db->query("SELECT COUNT(*) as c FROM olist_products")->fetch_assoc()['c'] ?? 0;
    $after_images = $db->query("SELECT COUNT(*) as c FROM olist_product_images WHERE product_local_id > 0")->fetch_assoc()['c'] ?? 0;

    $log[] = "[DEPOIS] products=$after_products, olist_products=$after_olist, images_linked=$after_images";

    // 9. CRIAR CSV DE AUDITORIA
    $audit_file = __DIR__ . '/../storage/reports/catalogo_olist_reparo.csv';
    @mkdir(dirname($audit_file), 0755, true);

    $csv_handle = fopen($audit_file, 'w');
    fputcsv($csv_handle, ['sku', 'olist_product_id', 'product_local_id', 'product_name', 'exists_in_products', 'images_count', 'primary_image_url', 'status']);

    $audit_sql = "SELECT
        op.sku,
        op.olist_product_id,
        COALESCE(p.id, 0) as product_local_id,
        op.name as product_name,
        IF(p.id IS NOT NULL, 'SIM', 'NÃO') as exists_in_products,
        op.images_count,
        op.primary_image_url,
        IF(op.primary_image_url IS NOT NULL AND op.primary_image_url != '', 'COM_IMAGEM', 'SEM_IMAGEM') as status
    FROM olist_products op
    LEFT JOIN products p ON p.sku = op.sku
    ORDER BY op.sku";

    $audit_result = $db->query($audit_sql);
    if ($audit_result) {
        while ($row = $audit_result->fetch_assoc()) {
            fputcsv($csv_handle, $row);
        }
    }
    fclose($csv_handle);

    $log[] = "[OK] CSV de auditoria criado em $audit_file";

    // 10. REGISTRAR EM LOGS
    $log_entry = [
        'timestamp' => date('c'),
        'action' => 'reparar_catalogo_olist',
        'before' => [
            'products' => $before_products,
            'olist_products' => $before_olist,
            'images' => $before_images
        ],
        'after' => [
            'products' => $after_products,
            'olist_products' => $after_olist,
            'images_linked' => $after_images
        ],
        'duration_sec' => round(microtime(true) - $start_time, 2),
        'log' => $log
    ];

    $log_file = __DIR__ . '/../logs/reparacao-olist-' . date('Y-m-d-H-i-s') . '.json';
    @mkdir(dirname($log_file), 0755, true);
    file_put_contents($log_file, json_encode($log_entry, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

    $db->close();

    // RESPOSTA FINAL
    echo json_encode([
        'ok' => true,
        'produtos_antes' => $before_products,
        'produtos_depois' => $after_products,
        'produtos_esperados' => 196,
        'images_linked' => $after_images,
        'primary_images_updated' => $updated_primary ?? 0,
        'csv_auditoria' => $audit_file,
        'log_file' => $log_file,
        'duracao_seg' => round(microtime(true) - $start_time, 2),
        'status' => $after_products >= 196 ? 'COMPLETO' : 'INCOMPLETO',
        'log' => $log
    ], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    $db->close();
    exit(json_encode([
        'ok' => false,
        'erro' => $e->getMessage(),
        'trace' => $e->getTraceAsString(),
        'log' => $log
    ]));
}
?>

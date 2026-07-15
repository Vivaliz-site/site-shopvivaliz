<?php
require_once __DIR__ . '/../includes/admin-guard.php';
/**
 * Sincroniza todos os produtos de olist_products para products
 * Simples e direto - sem dependências complexas
 */

header('Content-Type: application/json; charset=utf-8');
set_time_limit(300);

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
$start = microtime(true);

try {
    // 1. CONTAR ANTES
    $before_products = $db->query("SELECT COUNT(*) as c FROM products")->fetch_assoc()['c'] ?? 0;
    $before_olist = $db->query("SELECT COUNT(*) as c FROM olist_products")->fetch_assoc()['c'] ?? 0;

    $log[] = "[ANTES] products=$before_products, olist_products=$before_olist";

    // 2. IMPORTAR: INSERT todos de olist_products que não existem em products
    // Usar olist_id como chave única
    $sql_insert = "INSERT INTO products (olist_id, sku, name, price, description, stock, image_url, active, created_at, updated_at)
    SELECT
        op.olist_id,
        op.sku,
        op.name,
        op.price,
        op.description,
        CAST(COALESCE(op.stock_quantity, 0) AS UNSIGNED),
        op.primary_image_url,
        1,
        NOW(),
        NOW()
    FROM olist_products op
    LEFT JOIN products p ON p.olist_id = op.olist_id
    WHERE p.id IS NULL
    ON DUPLICATE KEY UPDATE
        name=VALUES(name),
        price=VALUES(price),
        description=VALUES(description),
        stock=VALUES(stock),
        updated_at=NOW()";

    if ($db->query($sql_insert)) {
        $inserted = $db->affected_rows;
        $log[] = "[OK] Importados $inserted produtos de olist_products";
    } else {
        $log[] = "[ERRO] INSERT: " . $db->error;
        throw new Exception($db->error);
    }

    // 3. VINCULAR IMAGENS
    $sql_link = "UPDATE olist_product_images img
    JOIN olist_products op ON img.olist_product_id = op.olist_id
    JOIN products p ON p.olist_id = op.olist_id
    SET img.product_local_id = p.id
    WHERE (img.product_local_id IS NULL OR img.product_local_id = 0)";

    if ($db->query($sql_link)) {
        $linked = $db->affected_rows;
        $log[] = "[OK] Vinculadas $linked imagens";
    } else {
        $log[] = "[AVISO] LINK imagens: " . $db->error;
    }

    // 4. CONTAR DEPOIS
    $after_products = $db->query("SELECT COUNT(*) as c FROM products")->fetch_assoc()['c'] ?? 0;
    $after_olist = $db->query("SELECT COUNT(*) as c FROM olist_products")->fetch_assoc()['c'] ?? 0;
    $after_images = $db->query("SELECT COUNT(*) as c FROM olist_product_images WHERE product_local_id > 0")->fetch_assoc()['c'] ?? 0;

    $log[] = "[DEPOIS] products=$after_products, olist_products=$after_olist, images_linked=$after_images";

    $db->close();

    echo json_encode([
        'ok' => true,
        'antes' => [
            'products' => $before_products,
            'olist_products' => $before_olist
        ],
        'depois' => [
            'products' => $after_products,
            'olist_products' => $after_olist,
            'images_linked' => $after_images
        ],
        'importados' => $after_products - $before_products,
        'duracao_seg' => round(microtime(true) - $start, 2),
        'status' => $after_products >= 196 ? '[OK] 196+ PRODUTOS' : "[AVISO] Apenas $after_products de 196",
        'log' => $log
    ], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    $db->close();
    exit(json_encode([
        'ok' => false,
        'erro' => $e->getMessage(),
        'log' => $log
    ]));
}
?>

<?php
require __DIR__ . "/config/constants.php";
$oldJsonPath = __DIR__ . "/api/catalog/fallback-products.json";
$oldJson = file_exists($oldJsonPath) ? json_decode(file_get_contents($oldJsonPath), true) : [];
$categoryMap = [];
if (is_array($oldJson)) {
    foreach ($oldJson as $item) {
        if (isset($item["sku"]) && isset($item["category"])) {
            $categoryMap[$item["sku"]] = $item["category"];
        }
    }
}
$db = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME, DB_PORT);
$res = $db->query("SELECT sku, name, description, price, stock, image_url, id as olist_product_id FROM products WHERE active = 1");
$arr = [];
while ($row = $res->fetch_assoc()) {
    $sku = $row["sku"];
    $row["category"] = isset($categoryMap[$sku]) && $categoryMap[$sku] !== "" ? $categoryMap[$sku] : "Geral";
    $arr[] = $row;
}
file_put_contents(__DIR__ . "/api/catalog/fallback-products.json", json_encode($arr, JSON_UNESCAPED_UNICODE));
echo "JSON atualizado com " . count($arr) . " produtos com categorias restauradas!";


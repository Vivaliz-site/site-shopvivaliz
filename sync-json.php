<?php
require __DIR__ . "/config/constants.php";
$db = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME, DB_PORT);
$res = $db->query("SELECT sku, name, description, price, stock, image_url, \"Lançamentos\" as category FROM products WHERE active = 1");
$arr = [];
while ($row = $res->fetch_assoc()) {
    $arr[] = $row;
}
file_put_contents(__DIR__ . "/api/catalog/fallback-products.json", json_encode($arr, JSON_UNESCAPED_UNICODE));
echo "JSON atualizado com " . count($arr) . " produtos.";


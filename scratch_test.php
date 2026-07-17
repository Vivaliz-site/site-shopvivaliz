<?php
function sv_home_lower($s){ return function_exists('mb_strtolower') ? mb_strtolower($s,'UTF-8') : strtolower($s); }
function sv_home_category_icon($c){ return '/x.jpg'; }
function sv_home_catalog_source_rows(){
  $d = json_decode(file_get_contents(__DIR__.'/api/catalog/fallback-products.json'), true);
  return $d;
}
require __DIR__.'/includes/layout-loader.php';

function sv_home_top_categories(int $limit = 8): array
{
    $counts = [];
    foreach (sv_home_catalog_source_rows() as $row) {
        if (!is_array($row)) continue;
        $category = trim((string)($row['category'] ?? ''));
        if ($category === '') continue;
        $counts[$category] = ($counts[$category] ?? 0) + 1;
    }
    arsort($counts);
    $orderedNames = [];
    $catalogByKey = [];
    foreach (array_keys($counts) as $name) { $catalogByKey[sv_home_lower($name)] = $name; }
    foreach (sv_get_categories_order() as $key) {
        $key = sv_home_lower(trim((string)$key));
        if (isset($catalogByKey[$key])) $orderedNames[] = $catalogByKey[$key];
    }
    $orderedNames = array_values(array_unique(array_merge($orderedNames, array_keys($counts))));
    $result = [];
    foreach ($orderedNames as $category) {
        if (!isset($counts[$category])) continue;
        $result[] = ['name'=>$category,'count'=>$counts[$category]];
        if (count($result) >= $limit) break;
    }
    return $result;
}
var_dump(sv_home_top_categories(10));

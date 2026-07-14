<?php
/**
 * Debug script para verificar qual versão do products.php o servidor está usando
 * URL: https://dev.shopvivaliz.com.br/api/debug-version.php
 */

header('Content-Type: application/json; charset=utf-8');

$products_php = __DIR__ . '/catalog/products.php';
$content = file_get_contents($products_php) ?: '';

// Verificar qual versão
$is_erp_version = strpos($content, 'fetch_erp_products') !== false;
$is_db_version = strpos($content, 'svcat_db_products') !== false;

// Verificar commit
$git_dir = dirname(__DIR__) . '/.git';
$git_head = is_dir($git_dir) ? trim(file_get_contents("$git_dir/HEAD")) : 'unknown';

// Extrair commit atual
$commit = 'unknown';
if (preg_match('/ref: refs\/heads\/(.+)/', $git_head, $m)) {
  $branch_file = "$git_dir/refs/heads/" . $m[1];
  $commit = file_exists($branch_file) ? trim(file_get_contents($branch_file)) : 'unknown';
}

$result = [
  'ok' => true,
  'timestamp' => date('Y-m-d H:i:s UTC', time()),
  'server' => $_SERVER['HTTP_HOST'] ?? 'unknown',
  'php_version' => phpversion(),
  'api_version' => [
    'is_erp_version' => $is_erp_version,
    'is_db_version' => $is_db_version,
    'has_fetch_erp_products' => $is_erp_version,
    'has_svcat_db_products' => $is_db_version,
  ],
  'git' => [
    'commit' => substr($commit, 0, 7),
    'branch' => basename(trim(preg_replace('/^ref: refs\/heads\//', '', $git_head))),
  ],
  'recommendations' => [],
];

// Diagnóstico
if ($is_erp_version && $is_db_version) {
  $result['status'] = 'mixed';
  $result['recommendations'][] = 'Arquivo tem AMBOS os códigos - verificar se é transição';
} elseif ($is_erp_version) {
  $result['status'] = 'erp_olist';
  $result['recommendations'][] = 'Correto: buscando do ERP Olist';
} else {
  $result['status'] = 'ecommerce_or_db';
  $result['recommendations'][] = 'ERRO: Ainda usando ecommerce/database';
  $result['recommendations'][] = 'Ação: git reset --hard origin/main';
}

echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
?>

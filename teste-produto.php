<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<!-- Teste iniciado -->";
echo "<!-- Passo 1: Função sv_product_catalog -->";

$path = __DIR__ . '/api/catalog/fallback-products.json';
echo "<!-- Arquivo: $path -->";
echo "<!-- Existe: " . (is_file($path) ? 'sim' : 'nao') . " -->";

if (!is_file($path)) {
    die("Arquivo não encontrado");
}

$contents = file_get_contents($path);
echo "<!-- Tamanho: " . strlen($contents) . " bytes -->";

$d = json_decode($contents, true);
echo "<!-- JSON decode OK: " . (is_array($d) ? 'sim' : 'nao') . " -->";
echo "<!-- Produtos: " . count($d) . " -->";

echo "<!-- Teste concluído -->";
echo "<pre>OK</pre>";
?>

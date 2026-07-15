<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

$_GET = [
    'sku' => '9976-284',
    'name' => 'Maçaneta',
    'image' => 'http://example.com/img.jpg',
    'price' => '0',
    'olist_product_id' => '337703208'
];

// Medir tempo
$start = microtime(true);

try {
    // Incluir funções
    include 'produto.php';
    $elapsed = microtime(true) - $start;
    echo "\n<!-- Sucesso! Tempo: {$elapsed}s -->";
} catch (Throwable $e) {
    echo "\n<!-- Erro: " . $e->getMessage() . " -->";
    echo "\n<!-- Arquivo: " . $e->getFile() . " -->";
    echo "\n<!-- Linha: " . $e->getLine() . " -->";
}
?>

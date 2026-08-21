<?php
/**
 * Teste: Consegue ler products-cache.json?
 */

$root = dirname(__FILE__);
$cache_file = $root . '/storage/products-cache.json';

echo "Testando leitura de cache...\n";
echo "Caminho: $cache_file\n";
echo "Existe? " . (is_file($cache_file) ? "SIM" : "NÃO") . "\n";

if (is_file($cache_file)) {
    echo "Tamanho: " . filesize($cache_file) . " bytes\n";
    echo "Readable? " . (is_readable($cache_file) ? "SIM" : "NÃO") . "\n";

    $content = file_get_contents($cache_file);
    if ($content) {
        $data = json_decode($content, true);
        echo "\nJSON válido? SIM\n";
        echo "Total de itens: " . count($data['itens'] ?? []) . "\n";

        if (isset($data['itens'])) {
            echo "\nPrimeiros 2 produtos:\n";
            foreach (array_slice($data['itens'], 0, 2) as $item) {
                echo "  - " . $item['descricao'] . "\n";
            }
        }
    } else {
        echo "Falha ao ler arquivo!\n";
    }
} else {
    echo "Arquivo não existe!\n";
}
?>

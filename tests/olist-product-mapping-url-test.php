<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/olist-erp-webhook.php';
$product = [
    'idMapeamento' => '1',
    'codigo' => 'PARENT-1',
    'nome' => 'Produto Pai',
    'variacoes' => [[
        'idMapeamento' => '2',
        'codigo' => 'CHILD-1',
    ]],
];
$mappings = svoei_product_mapping_response($product, 'https://shopvivaliz.com.br');
if (($mappings[0]['urlProduto'] ?? '') === '' || ($mappings[1]['urlProduto'] ?? '') !== ($mappings[0]['urlProduto'] ?? '')) {
    throw new RuntimeException('variations must point to the canonical parent product URL');
}
echo "olist-product-mapping-url: ok\n";

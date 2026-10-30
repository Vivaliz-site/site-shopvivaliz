<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/includes/erp-amazon-price.php';

function amazon_check(bool $ok, string $message): void
{
    if (!$ok) throw new RuntimeException($message);
}

$table = sveap_parse_table([
    'id' => 991, 'descricao' => 'Amazon', 'acrescimoDesconto' => 10,
    'excecoes' => [['idProduto' => 20, 'codigo' => 'EX-20', 'preco' => 37.89, 'precoPromocional' => 32.0]],
]);
$default = ['id' => 10, 'sku' => 'SKU10', 'precos' => ['preco' => 15.0, 'precoPromocional' => 12.0]];
$exception = ['id' => 20, 'sku' => 'EX-20', 'precos' => ['preco' => 15.0, 'precoPromocional' => 11.0]];
$default = sveap_apply_table($default, $table);
$exception = sveap_apply_table($exception, $table);
amazon_check($default['precos']['preco'] === 16.5, 'General table percent failed');
amazon_check($default['precos']['precoPromocional'] === 13.2, 'General promotional percent failed');
amazon_check($exception['precos']['preco'] === 37.89, 'Amazon exception ignored');
amazon_check($exception['precos']['precoPromocional'] === 32.0, 'Amazon exception promo ignored');
amazon_check($default['_price_list_id'] === 991, 'Amazon provenance missing');
amazon_check(sveap_apply_table($default, $table)['precos']['preco'] === 16.5, 'Price applied twice');

$reset = sveap_parse_table(['id' => 991, 'descricao' => 'Amazon', 'acrescimoDesconto' => 0, 'excecoes' => []]);
amazon_check(sveap_apply_table($exception, $reset)['precos']['preco'] === 15.0, 'Exception removal must restore base');

foreach ([
    ['id' => 982, 'descricao' => 'PDV', 'acrescimoDesconto' => 45, 'excecoes' => []],
    ['id' => 991, 'descricao' => 'Amazon', 'acrescimoDesconto' => 0],
    ['id' => 991, 'descricao' => 'Amazon', 'acrescimoDesconto' => 0, 'excecoes' => [['idProduto' => 2, 'preco' => 0]]],
] as $invalid) {
    $failed = false;
    try { sveap_parse_table($invalid); } catch (RuntimeException $e) { $failed = true; }
    amazon_check($failed, 'Invalid Amazon list must fail closed');
}

echo "amazon-price-table-contract: ok\n";

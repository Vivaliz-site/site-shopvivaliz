<?php
declare(strict_types=1);

/**
 * P0 regressao Tiny API v3: o PUT nao deve enviar objeto 'precos'
 * parcial com o custo ausente/zerado. Teste local: nenhuma chamada externa.
 */
require_once __DIR__ . '/../includes/tiny-product-push.php';

$cases = [
    'price_without_cost' => [['name' => 'Produto', 'sku' => 'SKU', 'price' => 100.0], false],
    'price_with_zero_cost' => [['name' => 'Produto', 'sku' => 'SKU', 'price' => 100.0, 'prices' => ['cost_price' => 0]], false],
    'price_with_negative_cost' => [['name' => 'Produto', 'sku' => 'SKU', 'price' => 100.0, 'prices' => ['cost_price' => -10]], false],
    'promotion_without_cost' => [['name' => 'Produto', 'sku' => 'SKU', 'prices' => ['promotional_price' => 99.0]], false],
    'price_with_positive_cost' => [['name' => 'Produto', 'sku' => 'SKU', 'price' => 100.0, 'prices' => ['cost_price' => 30.0]], true],
    'only_non_price_fields' => [['name' => 'Produto', 'sku' => 'SKU', 'notes' => 'Atualizacao editorial'], true],
];

if (!function_exists('svtpp_price_payload_includes_nonzero_cost')) {
    fwrite(STDERR, "RED: guarda de integridade do custo ausente\n");
    exit(1);
}

foreach ($cases as $id => [$product, $expected]) {
    $payload = svtpp_build_payload($product);
    $actual = svtpp_price_payload_includes_nonzero_cost($payload);
    if ($actual !== $expected) {
        fwrite(STDERR, "FAIL: {$id}; expected=" . ($expected ? 'true' : 'false')
            . " actual=" . ($actual ? 'true' : 'false') . "\n");
        exit(1);
    }
}

echo "OK: Tiny PUT de produto nao aceita preco sem custo positivo\n";

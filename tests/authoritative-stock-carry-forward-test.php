<?php
declare(strict_types=1);

function stock_carry_assert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$helper = dirname(__DIR__) . '/includes/catalog-authoritative-stock-carry.php';
stock_carry_assert(is_file($helper), 'helper de carry-forward autoritativo precisa existir');
require_once $helper;

$previous = [
    [
        'id' => '101',
        'sku' => 'SKU-101',
        'estoque_disponivel' => 7,
        'estoque_sync_at' => '2026-08-22T02:00:00+00:00',
    ],
    [
        'id' => '102',
        'sku' => 'SKU-102',
        'estoque_disponivel' => 0,
        'estoque_sync_at' => '2026-08-22T02:01:00+00:00',
    ],
    [
        'id' => '103',
        'sku' => 'SKU-103',
        'estoque_disponivel' => 9,
    ],
];

$index = svcs_authoritative_stock_index($previous);

$carried = svcs_carry_forward_authoritative_stock(['id' => '101', 'sku' => 'SKU-101'], $index);
stock_carry_assert(($carried['estoque_disponivel'] ?? null) === 7, 'estoque positivo validado deve ser preservado');
stock_carry_assert(($carried['estoque_sync_at'] ?? '') === '2026-08-22T02:00:00+00:00', 'evidencia temporal precisa acompanhar o estoque');

$zero = svcs_carry_forward_authoritative_stock(['id' => '102', 'sku' => 'SKU-102'], $index);
stock_carry_assert(array_key_exists('estoque_disponivel', $zero) && $zero['estoque_disponivel'] === 0, 'zero autoritativo tambem precisa ser preservado');

$unverified = svcs_carry_forward_authoritative_stock(['id' => '103', 'sku' => 'SKU-103'], $index);
stock_carry_assert(!array_key_exists('estoque_disponivel', $unverified), 'estoque sem estoque_sync_at nao pode ser carregado');

$new = svcs_carry_forward_authoritative_stock(['id' => '999', 'sku' => 'SKU-999'], $index);
stock_carry_assert(!array_key_exists('estoque_disponivel', $new), 'produto novo nao pode receber estoque inventado');

$fresh = svcs_carry_forward_authoritative_stock([
    'id' => '101',
    'sku' => 'SKU-101',
    'estoque_disponivel' => 3,
    'estoque_sync_at' => '2026-08-22T03:00:00+00:00',
], $index);
stock_carry_assert($fresh['estoque_disponivel'] === 3, 'estoque ja presente no item novo nao pode ser sobrescrito');

fwrite(STDOUT, "authoritative-stock-carry-forward: ok\n");

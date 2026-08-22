<?php
declare(strict_types=1);

/**
 * Build a lookup containing only stock values that were previously verified
 * by the authoritative ERP stock endpoint. Values without estoque_sync_at are
 * intentionally ignored so stale/listing-derived stock cannot be carried.
 *
 * @param array<int, mixed> $rows
 * @return array<string, array{estoque_disponivel:int, estoque_sync_at:string}>
 */
function svcs_authoritative_stock_index(array $rows): array
{
    $index = [];
    foreach ($rows as $row) {
        if (!is_array($row) || !array_key_exists('estoque_disponivel', $row)) continue;

        $syncAt = trim((string)($row['estoque_sync_at'] ?? ''));
        if ($syncAt === '' || !is_numeric($row['estoque_disponivel'])) continue;

        $evidence = [
            'estoque_disponivel' => max(0, (int)$row['estoque_disponivel']),
            'estoque_sync_at' => $syncAt,
        ];

        $id = trim((string)($row['id'] ?? $row['olist_product_id'] ?? ''));
        if ($id !== '') $index['id:' . $id] = $evidence;

        $sku = strtoupper(trim((string)($row['sku'] ?? $row['codigo'] ?? '')));
        if ($sku !== '') $index['sku:' . $sku] = $evidence;
    }
    return $index;
}

/**
 * Preserve the last verified stock only while a fresh active-product listing
 * is waiting for the authoritative stock refresh. Fresh stock already present
 * on the new item always wins, and unknown/new products stay unknown.
 *
 * @param array<string, mixed> $item
 * @param array<string, array{estoque_disponivel:int, estoque_sync_at:string}> $index
 * @return array<string, mixed>
 */
function svcs_carry_forward_authoritative_stock(array $item, array $index): array
{
    if (array_key_exists('estoque_disponivel', $item)) return $item;

    $keys = [];
    $id = trim((string)($item['id'] ?? $item['olist_product_id'] ?? ''));
    if ($id !== '') $keys[] = 'id:' . $id;

    $sku = strtoupper(trim((string)($item['sku'] ?? $item['codigo'] ?? '')));
    if ($sku !== '') $keys[] = 'sku:' . $sku;

    foreach ($keys as $key) {
        if (!isset($index[$key])) continue;
        $item['estoque_disponivel'] = $index[$key]['estoque_disponivel'];
        $item['estoque_sync_at'] = $index[$key]['estoque_sync_at'];
        break;
    }

    return $item;
}

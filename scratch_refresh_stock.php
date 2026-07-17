<?php
declare(strict_types=1);

$fallbackPath = __DIR__ . '/api/catalog/fallback-products.json';
$cachePath = __DIR__ . '/storage/products-cache-ativos.json';

$fallback = json_decode((string)file_get_contents($fallbackPath), true);
$cache = json_decode((string)file_get_contents($cachePath), true);
$items = is_array($cache['itens'] ?? null) ? $cache['itens'] : [];

$erpBySku = [];
foreach ($items as $item) {
    if (!is_array($item)) continue;
    $sku = trim((string)($item['codigo'] ?? $item['sku'] ?? ''));
    if ($sku === '') continue;
    $stock = (int)($item['estoque_disponivel'] ?? (is_array($item['estoque'] ?? null) ? ($item['estoque']['quantidade'] ?? 0) : 0));
    $situacao = strtoupper(trim((string)($item['situacao'] ?? 'A')));
    $erpBySku[$sku] = ['stock' => $stock, 'active' => in_array($situacao, ['A', 'ATIVO', 'ACTIVE'], true)];
}

$updated = 0;
$deactivated = 0;
foreach ($fallback as $idx => $row) {
    if (!is_array($row)) continue;
    $sku = trim((string)($row['sku'] ?? ''));
    if ($sku === '' || !isset($erpBySku[$sku])) continue;

    $erp = $erpBySku[$sku];
    $oldStock = (int)($row['stock'] ?? 0);
    if ($oldStock !== $erp['stock']) {
        $fallback[$idx]['stock'] = $erp['stock'];
        $updated++;
    }
    if (!$erp['active'] && ($row['status'] ?? '') !== 'inactive') {
        $fallback[$idx]['status'] = 'inactive';
        $deactivated++;
    }
}

file_put_contents(
    $fallbackPath,
    json_encode($fallback, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
);

echo "Atualizados: $updated\n";
echo "Desativados (situacao != A no ERP): $deactivated\n";

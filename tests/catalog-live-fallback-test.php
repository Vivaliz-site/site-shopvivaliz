<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$fallbackPath = $root . '/api/catalog/fallback-products.json';

function svclf_assert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

svclf_assert(is_file($fallbackPath) && is_readable($fallbackPath), 'fallback-products.json precisa existir e ser legivel');
$raw = file_get_contents($fallbackPath);
svclf_assert(is_string($raw) && $raw !== '', 'fallback-products.json nao pode estar vazio');

try {
    $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
} catch (JsonException $error) {
    fwrite(STDERR, 'FAIL: fallback-products.json invalido: ' . $error->getMessage() . "\n");
    exit(1);
}

svclf_assert(is_array($decoded), 'fallback-products.json precisa decodificar como array');
svclf_assert(count($decoded) >= 20, 'fallback rastreado precisa manter um catalogo util');

require_once $root . '/includes/catalog-runtime.php';
$products = svcr_products();
svclf_assert($products === [], 'svcr_products deve falhar fechado quando o cache canonico de ativos estiver ausente');

ob_start();
require $root . '/google-merchant-feed.php';
$feed = (string)ob_get_clean();
$itemCount = substr_count($feed, '<item>');
svclf_assert($itemCount === 0, 'Merchant feed nao pode ressuscitar snapshot historico sem fonte canonica; itens=' . $itemCount);

printf(
    "catalog-live-fail-closed: ok products=%d merchant_items=%d historical_rows=%d\n",
    count($products),
    $itemCount,
    count(array_filter($decoded, 'is_array'))
);

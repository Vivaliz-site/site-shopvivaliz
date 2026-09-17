<?php
declare(strict_types=1);

function sv_product_video_sources_registry_path(?string $root = null): string
{
    $base = $root !== null ? rtrim($root, '/') : dirname(__DIR__);
    return $base . '/storage/product-video-sources.json';
}

function sv_product_video_sources_load(?string $root = null): array
{
    $path = sv_product_video_sources_registry_path($root);
    if (!is_file($path)) return [];
    $decoded = json_decode((string)file_get_contents($path), true);
    return is_array($decoded) ? $decoded : [];
}

function sv_product_video_sources_merge(array $product, array $registry): array
{
    $id = trim((string)($product['id'] ?? $product['olist_product_id'] ?? ''));
    $sku = trim((string)($product['sku'] ?? ''));
    $extra = ($id !== '' && isset($registry[$id]) && is_array($registry[$id])) ? $registry[$id] : [];
    if ($extra === [] && $sku !== '' && isset($registry['sku:' . $sku]) && is_array($registry['sku:' . $sku])) {
        $extra = $registry['sku:' . $sku];
    }
    if (trim((string)($product['video_url'] ?? '')) === '' && trim((string)($extra['direct_url'] ?? '')) !== '') {
        $product['video_url'] = trim((string)$extra['direct_url']);
    }
    if (trim((string)($extra['youtube_url'] ?? '')) !== '') $product['youtube_url'] = trim((string)$extra['youtube_url']);
    return $product;
}

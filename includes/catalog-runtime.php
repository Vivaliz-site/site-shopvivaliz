<?php
declare(strict_types=1);

/**
 * Canonical read-only catalog source for storefront, checkout and health APIs.
 * Prefer the curated fallback when populated; otherwise normalize the live
 * Olist/Tiny detail cache produced by daemon-sync-products.py.
 */
function svcr_products(): array {
    $root = dirname(__DIR__);
    $fallback = $root . '/api/catalog/fallback-products.json';
    $rows = is_file($fallback) ? json_decode((string)file_get_contents($fallback), true) : [];
    if (is_array($rows) && $rows !== []) {
        return array_values(array_filter($rows, 'is_array'));
    }

    $cache = $root . '/storage/products-cache-ativos.json';
    $payload = is_file($cache) ? json_decode((string)file_get_contents($cache), true) : [];
    if (!is_array($payload)) {
        return [];
    }

    // The synchronizer has produced more than one cache envelope over time.
    // Accept all known formats, including a plain top-level product list.
    $items = [];
    foreach (['itens', 'items', 'produtos', 'products', 'data'] as $key) {

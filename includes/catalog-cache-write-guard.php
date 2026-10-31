<?php
declare(strict_types=1);

/**
 * The Amazon ERP table (991) is the only commercial writer of the storefront
 * catalog. Secondary image/metadata writers may change presentation fields,
 * but must not change identity, prices, stock, the source, or row count.
 *
 * All writers use the same cross-language lock (Python daemon, Olist webhook,
 * stock enrichment and this PHP guard). A write based on stale cache bytes
 * fails closed rather than overwriting a more recent ERP reconciliation.
 */

/** @return array<string,mixed> */
function svcc_nonpresentation_snapshot(array $payload): array
{
    $allowed = [
        'descricao', 'nome', 'name', 'categoria',
        'descricaoComplementar', 'descricao_complementar', 'description',
        'images', 'imagens', 'gallery', 'galeria',
        'image_url', 'primary_image_url', 'imagem_principal_url', 'imagem',
    ];
    $walk = static function (array $node) use (&$walk, $allowed): array {
        if (array_is_list($node)) {
            return array_map(static fn(mixed $item): mixed => is_array($item) ? $walk($item) : $item, $node);
        }
        // Only individual ERP products may have presentation fields removed
        // for comparison. Never omit top-level price/source provenance.
        if (isset($node['sku']) || isset($node['codigo']) || isset($node['olist_product_id'])) {
            foreach ($allowed as $key) unset($node[$key]);
        }
        foreach ($node as $key => $value) {
            if (is_array($value)) $node[$key] = $walk($value);
        }
        return $node;
    };
    return $walk($payload);
}

function svcc_write_presentation_if_current(string $path, string $originalRaw, string $nextRaw): void
{
    $original = json_decode($originalRaw, true, 512, JSON_THROW_ON_ERROR);
    $next = json_decode($nextRaw, true, 512, JSON_THROW_ON_ERROR);
    if (!is_array($original) || !is_array($next)) {
        throw new RuntimeException('catalog_presentation_payload_invalid');
    }
    foreach ([$original, $next] as $payload) {
        if ((int)($payload['price_list_id'] ?? 0) !== 991
            || ($payload['price_source'] ?? '') !== 'olist_tiny_amazon') {
            throw new RuntimeException('catalog_not_authoritative_amazon_991');
        }
    }
    if (svcc_nonpresentation_snapshot($original) !== svcc_nonpresentation_snapshot($next)) {
        throw new RuntimeException('catalog_commercial_or_identity_change_forbidden');
    }

    $directory = dirname($path);
    $lock = @fopen($directory . '/olist-erp-webhook.lock', 'c+');
    if (!is_resource($lock)) throw new RuntimeException('catalog_shared_lock_unavailable');
    $locked = false;
    $temporary = null;
    try {
        $locked = flock($lock, LOCK_EX);
        if (!$locked) throw new RuntimeException('catalog_shared_lock_failed');
        $live = @file_get_contents($path);
        if (!is_string($live) || !hash_equals(hash('sha256', $originalRaw), hash('sha256', $live))) {
            throw new RuntimeException('catalog_changed_during_presentation_update_retry_later');
        }
        $temporary = @tempnam($directory, '.catalog-presentation-');
        if (!is_string($temporary)) throw new RuntimeException('catalog_temporary_file_unavailable');
        if (file_put_contents($temporary, $nextRaw, LOCK_EX) === false
            || !chmod($temporary, 0660) || !rename($temporary, $path)) {
            throw new RuntimeException('catalog_atomic_presentation_write_failed');
        }
        $temporary = null;
        if (@file_get_contents($path) !== $nextRaw) {
            throw new RuntimeException('catalog_presentation_readback_failed');
        }
    } finally {
        if ($temporary !== null && is_file($temporary)) @unlink($temporary);
        if ($locked) flock($lock, LOCK_UN);
        fclose($lock);
    }
}

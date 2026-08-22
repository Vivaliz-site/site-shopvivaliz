<?php
declare(strict_types=1);

/**
 * Helpers for the Google Merchant Center XML feed.
 *
 * Product identifiers must come from manufacturer/catalog data. Never derive
 * an MPN from the shop SKU and never invent a GTIN: incorrect identifiers can
 * reduce matching quality or lead to Merchant Center diagnostics.
 */

function svgf_scalar_text(mixed $value): string
{
    if (is_scalar($value)) {
        return trim((string)$value);
    }
    if (is_array($value)) {
        foreach (['name', 'nome', 'value', 'codigo', 'code'] as $key) {
            if (isset($value[$key]) && is_scalar($value[$key])) {
                $text = trim((string)$value[$key]);
                if ($text !== '') return $text;
            }
        }
    }
    return '';
}

/**
 * Normalize and checksum-validate GTIN-8/12/13/14 values.
 */
function svgf_normalize_gtin(mixed $value): string
{
    $raw = svgf_scalar_text($value);
    if ($raw === '') return '';

    $digits = preg_replace('/\D+/', '', $raw) ?? '';
    $length = strlen($digits);
    if (!in_array($length, [8, 12, 13, 14], true)) return '';

    $expectedCheckDigit = (int)$digits[$length - 1];
    $sum = 0;
    $weight = 3;
    for ($index = $length - 2; $index >= 0; $index--) {
        $sum += ((int)$digits[$index]) * $weight;
        $weight = $weight === 3 ? 1 : 3;
    }
    $calculated = (10 - ($sum % 10)) % 10;

    return $calculated === $expectedCheckDigit ? $digits : '';
}

function svgf_first_gtin(array $row): string
{
    foreach ([
        'gtin',
        'ean',
        'ean13',
        'barcode',
        'codigo_barras',
        'codigoBarras',
        'codigo_barra',
        'codigoBarra',
    ] as $field) {
        if (!array_key_exists($field, $row)) continue;
        $gtin = svgf_normalize_gtin($row[$field]);
        if ($gtin !== '') return $gtin;
    }

    foreach (['taxation', 'tributacao', 'identifiers', 'identificadores'] as $nestedKey) {
        $nested = $row[$nestedKey] ?? null;
        if (!is_array($nested)) continue;
        foreach (['gtin', 'gtin_packaging', 'ean', 'barcode', 'codigo_barras', 'codigoBarras'] as $field) {
            if (!array_key_exists($field, $nested)) continue;
            $gtin = svgf_normalize_gtin($nested[$field]);
            if ($gtin !== '') return $gtin;
        }
    }

    return '';
}

function svgf_first_mpn(array $row): string
{
    foreach ([
        'mpn',
        'manufacturer_part_number',
        'manufacturerPartNumber',
        'part_number',
        'partNumber',
        'codigo_fabricante',
        'codigoFabricante',
    ] as $field) {
        $value = svgf_scalar_text($row[$field] ?? '');
        if ($value !== '' && strlen($value) <= 70) return $value;
    }
    return '';
}

function svgf_first_brand(array $row): string
{
    foreach (['brand', 'marca', 'manufacturer', 'fabricante'] as $field) {
        $value = svgf_scalar_text($row[$field] ?? '');
        if ($value !== '' && strlen($value) <= 70) return $value;
    }
    return '';
}

/** @return array{gtin:string,mpn:string,brand:string} */
function svgf_identifiers_from_row(array $row): array
{
    return [
        'gtin' => svgf_first_gtin($row),
        'mpn' => svgf_first_mpn($row),
        'brand' => svgf_first_brand($row),
    ];
}

/**
 * Walks catalog payloads in different Tiny/Olist/cache shapes and indexes
 * manufacturer identifiers by the store SKU.
 *
 * @param array<string,array{gtin:string,mpn:string,brand:string}> $map
 */
function svgf_collect_identifier_rows(mixed $node, array &$map): void
{
    if (!is_array($node)) return;

    $sku = '';
    foreach (['sku', 'codigo', 'code'] as $field) {
        $candidate = svgf_scalar_text($node[$field] ?? '');
        if ($candidate !== '') {
            $sku = $candidate;
            break;
        }
    }

    if ($sku !== '') {
        $found = svgf_identifiers_from_row($node);
        if (!isset($map[$sku])) {
            $map[$sku] = $found;
        } else {
            foreach (['gtin', 'mpn', 'brand'] as $field) {
                if (($map[$sku][$field] ?? '') === '' && $found[$field] !== '') {
                    $map[$sku][$field] = $found[$field];
                }
            }
        }
    }

    foreach ($node as $value) {
        if (is_array($value)) {
            svgf_collect_identifier_rows($value, $map);
        }
    }
}

/**
 * @return array<string,array{gtin:string,mpn:string,brand:string}>
 */
function svgf_catalog_identifier_map(string $root): array
{
    static $localCache = null;
    if ($localCache !== null) {
        return $localCache;
    }

    $apcu = function_exists('apcu_fetch') && function_exists('apcu_store');
    $apcuKey = 'sv_catalog_identifier_map_v1';
    if ($apcu) {
        $ok = false;
        $stored = apcu_fetch($apcuKey, $ok);
        if ($ok && is_array($stored)) {
            $localCache = $stored;
            return $localCache;
        }
    }

    $map = [];
    foreach ([
        $root . '/storage/products-cache-ativos.json',
    ] as $path) {
        if (!is_file($path) || !is_readable($path)) continue;
        $payload = json_decode((string)file_get_contents($path), true);
        if (!is_array($payload)) continue;
        svgf_collect_identifier_rows($payload, $map);
    }

    $localCache = $map;
    if ($apcu) {
        apcu_store($apcuKey, $localCache, 120); // Cache por 2 minutos
    }
    return $localCache;
}

function svgf_xml(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_XML1, 'UTF-8');
}

function svgf_limit(string $value, int $maxLength): string
{
    $value = trim($value);
    if ($value === '') return '';
    if (function_exists('mb_substr')) {
        return mb_substr($value, 0, $maxLength, 'UTF-8');
    }
    return substr($value, 0, $maxLength);
}

function svgf_allowed_feed_host(string $baseUrl = 'https://shopvivaliz.com.br'): string
{
    $host = strtolower((string)(parse_url($baseUrl, PHP_URL_HOST) ?: 'shopvivaliz.com.br'));
    $host = preg_replace('/^www\./', '', $host) ?: 'shopvivaliz.com.br';
    return $host;
}

function svgf_feed_url_is_allowed(string $url, string $baseUrl = 'https://shopvivaliz.com.br'): bool
{
    if (!filter_var($url, FILTER_VALIDATE_URL)) {
        return false;
    }
    $scheme = strtolower((string)(parse_url($url, PHP_URL_SCHEME) ?: ''));
    if ($scheme !== 'https') {
        return false;
    }
    $host = strtolower((string)(parse_url($url, PHP_URL_HOST) ?: ''));
    $host = preg_replace('/^www\./', '', $host) ?: '';
    if ($host === svgf_allowed_feed_host($baseUrl)) {
        return true;
    }

    // Product media is canonical only when it comes from the ERP/Tiny product
    // detail. Tiny stores those files in its S3 bucket; allow that exact origin
    // for Merchant image links without reintroducing local/manual image fallback.
    $path = (string)(parse_url($url, PHP_URL_PATH) ?: '');
    return $host === 's3.amazonaws.com' && str_starts_with($path, '/tiny-anexos-');
}

function svgf_feed_absolute_url(string $baseUrl, string $url): string
{
    $url = trim($url);
    if ($url === '') return '';
    if (str_starts_with($url, '//')) return '';
    if (preg_match('~^[a-z][a-z0-9+.-]*:~i', $url)) {
        return svgf_feed_url_is_allowed($url, $baseUrl) ? $url : '';
    }
    $absolute = rtrim($baseUrl, '/') . '/' . ltrim($url, '/');
    return svgf_feed_url_is_allowed($absolute, $baseUrl) ? $absolute : '';
}

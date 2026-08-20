<?php
declare(strict_types=1);

/**
 * Canonical route guard for ShopVivaliz product slugs.
 *
 * Goals:
 * - serve the exact live-catalog slug without redirect;
 * - redirect proven historical aliases to the current live SKU slug;
 * - never revive products from snapshots or use snapshot price/stock/status;
 * - preserve attribution parameters on safe canonical redirects;
 * - never serve stale product slugs as 200 pages with a different canonical.
 */

require_once __DIR__ . '/includes/catalog-runtime.php';

function sv_product_route_normalize(string $value): string
{
    $value = trim(rawurldecode($value));
    return function_exists('mb_strtolower')
        ? mb_strtolower($value, 'UTF-8')
        : strtolower($value);
}

function sv_product_route_catalog_row_by_slug(string $requestedSlug): ?array
{
    $requestedNorm = sv_product_route_normalize($requestedSlug);
    if ($requestedNorm === '') {
        return null;
    }

    foreach (svcr_products() as $row) {
        if (!is_array($row)) {
            continue;
        }
        $rowSlug = trim((string)($row['slug'] ?? ''));
        if ($rowSlug !== '' && sv_product_route_normalize($rowSlug) === $requestedNorm) {
            return $row;
        }
    }

    return null;
}

function sv_product_route_catalog_row_by_sku(string $sku): ?array
{
    $skuNorm = sv_product_route_normalize($sku);
    if ($skuNorm === '') {
        return null;
    }

    foreach (svcr_products() as $row) {
        if (!is_array($row)) {
            continue;
        }
        $rowSku = sv_product_route_normalize((string)($row['sku'] ?? ''));
        if ($rowSku !== '' && $rowSku === $skuNorm) {
            return $row;
        }
    }

    return null;
}

function sv_product_route_slugify(string $name, string $sku): string
{
    $accents = ['á'=>'a','à'=>'a','ã'=>'a','â'=>'a','ä'=>'a','é'=>'e','è'=>'e','ê'=>'e','ë'=>'e','í'=>'i','ì'=>'i','î'=>'i','ï'=>'i','ó'=>'o','ò'=>'o','õ'=>'o','ô'=>'o','ö'=>'o','ú'=>'u','ù'=>'u','û'=>'u','ü'=>'u','ç'=>'c','ñ'=>'n'];
    $lower = function_exists('mb_strtolower') ? mb_strtolower($name, 'UTF-8') : strtolower($name);
    $base = strtr($lower, $accents);
    $base = preg_replace('/[^a-z0-9]+/', '-', $base);
    $base = trim((string)$base, '-');
    $base = function_exists('mb_substr') ? mb_substr($base, 0, 60) : substr($base, 0, 60);
    $skuPart = strtolower((string)preg_replace('/[^a-zA-Z0-9]+/', '', $sku));
    return trim($base . '-' . $skuPart, '-') ?: $skuPart;
}

function sv_product_route_catalog_row_by_alias_slug(string $requestedSlug): ?array
{
    $requestedNorm = sv_product_route_normalize($requestedSlug);
    if ($requestedNorm === '') {
        return null;
    }

    $match = null;
    foreach (svcr_products() as $row) {
        if (!is_array($row)) {
            continue;
        }

        $canonicalSlug = trim((string)($row['slug'] ?? ''));
        $sku = trim((string)($row['sku'] ?? ''));
        $name = trim((string)($row['name'] ?? ''));
        $aliases = [];
        if ($name !== '' && $sku !== '') {
            $aliases[] = sv_product_route_slugify($name, $sku);
        }
        if ($name !== '' && function_exists('svcr_slug')) {
            $aliases[] = svcr_slug($name, '');
        }

        foreach (array_unique(array_filter($aliases)) as $alias) {
            if ($canonicalSlug !== '' && sv_product_route_normalize($canonicalSlug) === sv_product_route_normalize((string)$alias)) {
                continue;
            }
            if (sv_product_route_normalize((string)$alias) === $requestedNorm) {
                if ($match !== null) {
                    // Ambiguous aliases must not canonicalize to an arbitrary product.
                    return null;
                }
                $match = $row;
            }
        }
    }

    return $match;
}

function sv_product_route_catalog_row_by_sku_suffix(string $requestedSlug): ?array
{
    $requestedNorm = sv_product_route_normalize($requestedSlug);
    if ($requestedNorm === '') {
        return null;
    }

    $match = null;
    foreach (svcr_products() as $row) {
        if (!is_array($row)) {
            continue;
        }
        $rowSku = sv_product_route_normalize((string)($row['sku'] ?? ''));
        if ($rowSku === '') {
            continue;
        }
        if ($requestedNorm === $rowSku || str_ends_with($requestedNorm, '-' . $rowSku)) {
            if ($match !== null) {
                // Ambiguous SKU suffixes must fail closed.
                return null;
            }
            $match = $row;
        }
    }

    return $match;
}

function sv_product_route_row_slug(?array $row): ?string
{
    $slug = is_array($row) ? trim((string)($row['slug'] ?? '')) : '';
    return $slug !== '' ? $slug : null;
}

function sv_product_route_redirect(string $canonicalSlug): never
{
    $allowed = [
        'gclid', 'gbraid', 'wbraid', 'cupom',
        'utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term', 'utm_id',
    ];
    $query = [];
    foreach ($allowed as $key) {
        $value = $_GET[$key] ?? null;
        if (is_scalar($value) && trim((string)$value) !== '') {
            $query[$key] = trim((string)$value);
        }
    }

    $target = '/produto/' . rawurlencode($canonicalSlug);
    if ($query !== []) {
        $target .= '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }

    header('Location: ' . $target, true, 301);
    exit;
}

function sv_product_route_catalog_redirect(): never
{
    $allowed = ['gclid', 'gbraid', 'wbraid', 'utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term', 'utm_id'];
    $query = [];
    foreach ($allowed as $key) {
        $value = $_GET[$key] ?? null;
        if (is_scalar($value) && trim((string)$value) !== '') {
            $query[$key] = trim((string)$value);
        }
    }

    $target = '/catalogo/';
    if ($query !== []) {
        $target .= '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }

    header('Location: ' . $target, true, 301);
    exit;
}

function sv_product_route_not_found(): never
{
    http_response_code(404);
    header('Cache-Control: no-store', true);
    require __DIR__ . '/404.php';
    exit;
}

$rawSlug = $_GET['slug'] ?? '';
$requestedSlug = is_scalar($rawSlug) ? trim((string)$rawSlug) : '';
if ($requestedSlug === '') {
    sv_product_route_not_found();
}

// Direct requests to this internal controller are consolidated back to the
// public URL. Internal rewrites retain THE_REQUEST as /produto/....
$theRequest = (string)($_SERVER['THE_REQUEST'] ?? '');
if (stripos($theRequest, '/produto-slug-route.php') !== false) {
    header('Location: /produto/' . rawurlencode(rawurldecode($requestedSlug)), true, 301);
    exit;
}

// Exact current catalog slug: render normally with no redirect.
$currentRow = sv_product_route_catalog_row_by_slug($requestedSlug);
if ($currentRow !== null) {
    $_GET['slug'] = rawurldecode($requestedSlug);
    require __DIR__ . '/produto.php';
    exit;
}

// Some historical marketing URLs ended in the SKU. If that suffix uniquely
// identifies a currently active catalog product, canonicalize it.
$suffixRow = sv_product_route_catalog_row_by_sku_suffix($requestedSlug);
$suffixSlug = sv_product_route_row_slug($suffixRow);
if ($suffixSlug !== null) {
    sv_product_route_redirect($suffixSlug);
}

// Historical name-only/generated aliases that still resolve to one current
// catalog product should redirect instead of serving 200 with a different
// canonical. That is the Search Console "alternate/canonical mismatch" class.
$aliasRow = sv_product_route_catalog_row_by_alias_slug($requestedSlug);
$aliasSlug = sv_product_route_row_slug($aliasRow);
if ($aliasSlug !== null) {
    sv_product_route_redirect($aliasSlug);
}

// Historical numeric IDs resolve only against the current catalog. When no
// current match exists, direct the visitor to the current catalog instead of
// consulting a retired storefront snapshot or migration record.
$decodedSlug = rawurldecode($requestedSlug);
if (preg_match('/^(.+)-([0-9]+)$/u', $decodedSlug, $matches) === 1) {
    $baseRow = sv_product_route_catalog_row_by_slug(trim((string)$matches[1]));
    $baseSlug = sv_product_route_row_slug($baseRow);
    if ($baseSlug !== null) {
        sv_product_route_redirect($baseSlug);
    }
    sv_product_route_catalog_redirect();
}

// No proven current mapping: do not let produto.php revive legacy slugs as
// 200 pages. Return the actual missing-state so Google can clear stale URLs.
sv_product_route_not_found();

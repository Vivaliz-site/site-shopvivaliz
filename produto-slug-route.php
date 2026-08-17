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
 * - fall through to produto.php (including its 404 behavior) when no mapping
 *   can be proven.
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

/**
 * Historical editorial snapshots are allowed only as identity maps.
 * Commercial fields in these files are deliberately ignored.
 */
function sv_product_route_snapshot_sku(string $requestedSlug): ?string
{
    $requestedNorm = sv_product_route_normalize($requestedSlug);
    if ($requestedNorm === '') {
        return null;
    }

    foreach ([
        __DIR__ . '/storage/products-cache.json',
        __DIR__ . '/api/catalog/fallback-products.json',
    ] as $path) {
        if (!is_file($path) || !is_readable($path)) {
            continue;
        }

        $payload = json_decode((string)file_get_contents($path), true);
        if (!is_array($payload)) {
            continue;
        }

        $rows = $payload;
        foreach (['itens', 'items', 'produtos', 'products', 'data'] as $key) {
            if (isset($payload[$key]) && is_array($payload[$key])) {
                $rows = $payload[$key];
                break;
            }
        }

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $sku = trim((string)($row['sku'] ?? $row['codigo'] ?? $row['code'] ?? ''));
            if ($sku === '') {
                continue;
            }

            $name = trim((string)($row['name'] ?? $row['nome'] ?? ''));
            $candidates = [
                trim((string)($row['slug'] ?? '')),
                $name !== '' ? svcr_slug($name, '') : '',
                $name !== '' ? svcr_slug($name, $sku) : '',
            ];

            foreach ($candidates as $candidate) {
                if ($candidate !== '' && sv_product_route_normalize($candidate) === $requestedNorm) {
                    return $sku;
                }
            }
        }
    }

    return null;
}

/**
 * Persisted source URLs from migration are a second identity source.
 * The query is read-only and only returns SKU; live-catalog membership is
 * checked separately before a redirect is allowed.
 */
function sv_product_route_migration_sku(string $requestedSlug): ?string
{
    if (!class_exists('mysqli') || !function_exists('mysqli_init')) {
        return null;
    }

    $decodedSlug = trim(rawurldecode($requestedSlug));
    if (
        $decodedSlug === ''
        || strlen($decodedSlug) > 220
        || str_contains($decodedSlug, '/')
        || str_contains($decodedSlug, "\0")
    ) {
        return null;
    }

    $constants = __DIR__ . '/config/constants.php';
    if (!is_file($constants)) {
        return null;
    }

    try {
        require_once $constants;
        if (!defined('DB_NAME') || !defined('DB_USER')) {
            return null;
        }

        mysqli_report(MYSQLI_REPORT_OFF);
        $db = mysqli_init();
        if (!$db instanceof mysqli) {
            return null;
        }
        if (defined('MYSQLI_OPT_CONNECT_TIMEOUT')) {
            $db->options(MYSQLI_OPT_CONNECT_TIMEOUT, 1);
        }

        $connected = @$db->real_connect(
            (string)(defined('DB_HOST') ? DB_HOST : 'localhost'),
            (string)DB_USER,
            (string)(defined('DB_PASS') ? DB_PASS : ''),
            (string)DB_NAME,
            (int)(defined('DB_PORT') ? DB_PORT : 3306)
        );
        if (!$connected) {
            $db->close();
            return null;
        }
        $db->set_charset('utf8mb4');

        $patternSingular = '%/produto/' . $decodedSlug . '%';
        $patternPlural = '%/produtos/' . $decodedSlug . '%';
        $stmt = $db->prepare(
            "SELECT sku
               FROM olist_products
              WHERE sku IS NOT NULL
                AND sku <> ''
                AND detail_json IS NOT NULL
                AND (detail_json LIKE ? OR detail_json LIKE ?)
              ORDER BY updated_at DESC, id DESC
              LIMIT 1"
        );
        if (!$stmt) {
            $db->close();
            return null;
        }

        $stmt->bind_param('ss', $patternSingular, $patternPlural);
        if (!$stmt->execute()) {
            $stmt->close();
            $db->close();
            return null;
        }

        $result = $stmt->get_result();
        $row = $result ? $result->fetch_assoc() : null;
        $stmt->close();
        $db->close();

        $sku = is_array($row) ? trim((string)($row['sku'] ?? '')) : '';
        return $sku !== '' ? $sku : null;
    } catch (Throwable) {
        return null;
    }
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

$rawSlug = $_GET['slug'] ?? '';
$requestedSlug = is_scalar($rawSlug) ? trim((string)$rawSlug) : '';
if ($requestedSlug === '') {
    http_response_code(404);
    require __DIR__ . '/404.php';
    exit;
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

// Snapshot slug -> SKU -> live catalog slug. Snapshot commercial data is not
// trusted; the live SKU lookup is mandatory before redirecting.
$snapshotSku = sv_product_route_snapshot_sku($requestedSlug);
$snapshotSlug = sv_product_route_row_slug(
    $snapshotSku !== null ? sv_product_route_catalog_row_by_sku($snapshotSku) : null
);
if ($snapshotSlug !== null) {
    sv_product_route_redirect($snapshotSlug);
}

// Persisted migration source URL -> SKU -> live catalog slug.
$migrationSku = sv_product_route_migration_sku($requestedSlug);
$migrationSlug = sv_product_route_row_slug(
    $migrationSku !== null ? sv_product_route_catalog_row_by_sku($migrationSku) : null
);
if ($migrationSlug !== null) {
    sv_product_route_redirect($migrationSlug);
}

// Conservative compatibility for historical numeric IDs: strip a numeric tail
// only when the remaining slug exists exactly in the live catalog.
$decodedSlug = rawurldecode($requestedSlug);
if (preg_match('/^(.+)-([0-9]+)$/u', $decodedSlug, $matches) === 1) {
    $baseRow = sv_product_route_catalog_row_by_slug(trim((string)$matches[1]));
    $baseSlug = sv_product_route_row_slug($baseRow);
    if ($baseSlug !== null) {
        sv_product_route_redirect($baseSlug);
    }
}

// No proven current mapping: preserve the normal product page and 404 logic.
$_GET['slug'] = $decodedSlug;
require __DIR__ . '/produto.php';

<?php
declare(strict_types=1);

/**
 * Route guard for product slugs ending in digits.
 *
 * Historical ShopVivaliz/VNDA URLs used a trailing numeric identifier, e.g.
 * /produto/example-product-169, while some legitimate current products also
 * end in digits as part of their model (e.g. ...-th-05). Apache cannot tell
 * those cases apart safely, so canonicalization is resolved against the live
 * catalog before issuing a redirect.
 *
 * The legacy lookup is intentionally read-only and bounded by a short DB
 * connection timeout. Price and stock are never read or changed here.
 */

require_once __DIR__ . '/includes/catalog-runtime.php';

function sv_numeric_product_slug_normalize(string $value): string
{
    $value = trim(urldecode($value));
    return function_exists('mb_strtolower')
        ? mb_strtolower($value, 'UTF-8')
        : strtolower($value);
}

function sv_numeric_product_catalog_row_by_slug(string $requestedSlug): ?array
{
    $requestedNorm = sv_numeric_product_slug_normalize($requestedSlug);
    if ($requestedNorm === '') {
        return null;
    }

    foreach (svcr_products() as $row) {
        if (!is_array($row)) {
            continue;
        }
        $rowSlug = trim((string)($row['slug'] ?? ''));
        if ($rowSlug !== '' && sv_numeric_product_slug_normalize($rowSlug) === $requestedNorm) {
            return $row;
        }
    }

    return null;
}

function sv_numeric_product_catalog_row_by_sku(string $sku): ?array
{
    $skuNorm = sv_numeric_product_slug_normalize($sku);
    if ($skuNorm === '') {
        return null;
    }

    foreach (svcr_products() as $row) {
        if (!is_array($row)) {
            continue;
        }
        $rowSku = sv_numeric_product_slug_normalize((string)($row['sku'] ?? ''));
        if ($rowSku !== '' && $rowSku === $skuNorm) {
            return $row;
        }
    }

    return null;
}

function sv_numeric_product_catalog_slug(string $requestedSlug): ?string
{
    $row = sv_numeric_product_catalog_row_by_slug($requestedSlug);
    $slug = is_array($row) ? trim((string)($row['slug'] ?? '')) : '';
    return $slug !== '' ? $slug : null;
}

function sv_numeric_product_legacy_sku(string $requestedSlug): ?string
{
    if (!class_exists('mysqli') || !function_exists('mysqli_init')) {
        return null;
    }

    // The legacy source URLs are stored in olist_products.detail_json. Load
    // runtime DB aliases without exposing credentials and fail closed if the
    // DB is temporarily unavailable.
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

        $decodedSlug = trim(urldecode($requestedSlug));
        if ($decodedSlug === '' || preg_match('/^[a-z0-9][a-z0-9-]*$/i', $decodedSlug) !== 1) {
            $db->close();
            return null;
        }

        $pattern = '%/produto/' . $decodedSlug . '%';
        $stmt = $db->prepare(
            "SELECT sku
               FROM olist_products
              WHERE sku IS NOT NULL
                AND sku <> ''
                AND detail_json IS NOT NULL
                AND detail_json LIKE ?
              ORDER BY updated_at DESC, id DESC
              LIMIT 1"
        );
        if (!$stmt) {
            $db->close();
            return null;
        }

        $stmt->bind_param('s', $pattern);
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

function sv_numeric_product_legacy_catalog_slug(string $requestedSlug): ?string
{
    $sku = sv_numeric_product_legacy_sku($requestedSlug);
    if ($sku === null) {
        return null;
    }

    $row = sv_numeric_product_catalog_row_by_sku($sku);
    $slug = is_array($row) ? trim((string)($row['slug'] ?? '')) : '';
    return $slug !== '' ? $slug : null;
}

$rawSlug = $_GET['slug'] ?? '';
$requestedSlug = is_scalar($rawSlug) ? trim((string)$rawSlug) : '';

if ($requestedSlug === '') {
    http_response_code(404);
    require __DIR__ . '/404.php';
    exit;
}

// If somebody opens the internal route directly, consolidate it back to the
// public product URL. Internal rewrites keep THE_REQUEST as /produto/..., so
// this does not interfere with the normal route.
$theRequest = (string)($_SERVER['THE_REQUEST'] ?? '');
if (stripos($theRequest, '/produto-numeric-route.php') !== false) {
    header('Location: /produto/' . rawurlencode(urldecode($requestedSlug)), true, 301);
    exit;
}

// A legitimate current slug always wins, even when it ends in digits.
if (sv_numeric_product_catalog_slug($requestedSlug) !== null) {
    $_GET['slug'] = urldecode($requestedSlug);
    require __DIR__ . '/produto.php';
    exit;
}

$decodedSlug = urldecode($requestedSlug);
if (preg_match('/^(.+)-([0-9]+)$/', $decodedSlug, $matches) === 1) {
    // First use the persisted legacy source URL -> SKU mapping. This resolves
    // old VNDA product URLs whose marketing slug changed during migration.
    $legacyCanonicalSlug = sv_numeric_product_legacy_catalog_slug($decodedSlug);
    if ($legacyCanonicalSlug !== null) {
        header('Location: /produto/' . rawurlencode($legacyCanonicalSlug), true, 301);
        exit;
    }

    // Conservative fallback retained from the original route: only strip the
    // numeric tail when the resulting slug exists exactly in the live catalog.
    $baseSlug = trim((string)$matches[1]);
    $canonicalSlug = sv_numeric_product_catalog_slug($baseSlug);
    if ($canonicalSlug !== null) {
        header('Location: /produto/' . rawurlencode($canonicalSlug), true, 301);
        exit;
    }
}

// Preserve normal product-page behavior (including its 404 handling) when no
// safe canonical redirect can be proven from the catalog.
$_GET['slug'] = $decodedSlug;
require __DIR__ . '/produto.php';

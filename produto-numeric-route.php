<?php
declare(strict_types=1);

/**
 * Route guard for product slugs ending in digits.
 *
 * Historical ShopVivaliz URLs used a trailing numeric identifier, e.g.
 * /produto/example-product-169, while some legitimate current products also
 * end in digits as part of their model (e.g. ...-th-05). Apache cannot tell
 * those cases apart safely, so canonicalization is resolved against the live
 * catalog before issuing a redirect.
 */

require_once __DIR__ . '/includes/catalog-runtime.php';

function sv_numeric_product_slug_normalize(string $value): string
{
    $value = trim(urldecode($value));
    return function_exists('mb_strtolower')
        ? mb_strtolower($value, 'UTF-8')
        : strtolower($value);
}

function sv_numeric_product_catalog_slug(string $requestedSlug): ?string
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
            return $rowSlug;
        }
    }

    return null;
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

// Only treat the numeric tail as a legacy identifier when removing it yields
// a product that actually exists in the current catalog.
$decodedSlug = urldecode($requestedSlug);
if (preg_match('/^(.+)-([0-9]+)$/', $decodedSlug, $matches) === 1) {
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

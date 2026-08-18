<?php
declare(strict_types=1);

/**
 * Remove only the known legacy www sitemap from ShopVivaliz Search Console.
 *
 * This command is deliberately allow-listed and idempotent. It will never
 * delete the canonical https://shopvivaliz.com.br/sitemap.xml entry.
 */

$root = dirname(__DIR__);
$constants = $root . '/config/constants.php';
if (is_file($constants)) {
    require_once $constants;
}

$autoload = $root . '/vendor/autoload.php';
if (is_file($autoload)) {
    require_once $autoload;
} else {
    require_once $root . '/src/Google/OAuthTokenProvider.php';
    require_once $root . '/src/Google/GoogleApiClient.php';
}

use ShopVivaliz\Google\GoogleApiClient;
use ShopVivaliz\Google\OAuthTokenProvider;

const GSC_CANONICAL_SITEMAP = 'https://shopvivaliz.com.br/sitemap.xml';
const GSC_LEGACY_WWW_SITEMAP = 'https://www.shopvivaliz.com.br/sitemap.xml';
const GSC_PRODUCTION_HOST = 'shopvivaliz.com.br';

function sitemap_cleanup_error_message(array $response): string
{
    $body = $response['body'] ?? null;
    if (is_array($body)) {
        $message = $body['error']['message'] ?? $body['error_description'] ?? $body['error'] ?? '';
        if (is_scalar($message) && trim((string)$message) !== '') {
            return trim((string)$message);
        }
    }
    return 'HTTP ' . (int)($response['status'] ?? 0);
}

/** @param array<int,array<string,mixed>> $entries */
function sitemap_cleanup_resolve_site_url(array $entries): string
{
    $preferred = trim((string)(getenv('GOOGLE_SEARCH_CONSOLE_SITE_URL') ?: ''));
    if ($preferred !== '') {
        foreach ($entries as $entry) {
            if ((string)($entry['siteUrl'] ?? '') === $preferred) {
                return $preferred;
            }
        }
        throw new RuntimeException('Configured Search Console property is not available to this OAuth user.');
    }

    foreach ($entries as $entry) {
        $siteUrl = trim((string)($entry['siteUrl'] ?? ''));
        if ($siteUrl === 'sc-domain:' . GSC_PRODUCTION_HOST) {
            return $siteUrl;
        }
    }

    foreach ($entries as $entry) {
        $siteUrl = trim((string)($entry['siteUrl'] ?? ''));
        if ($siteUrl === '') {
            continue;
        }
        $host = strtolower((string)parse_url($siteUrl, PHP_URL_HOST));
        if ($host === GSC_PRODUCTION_HOST) {
            return $siteUrl;
        }
    }

    throw new RuntimeException('No Search Console property matching the ShopVivaliz production host was found.');
}

try {
    $api = new GoogleApiClient(OAuthTokenProvider::fromEnvironment());

    $sitesResponse = $api->request('GET', 'https://www.googleapis.com/webmasters/v3/sites');
    if ($sitesResponse['status'] < 200 || $sitesResponse['status'] >= 300 || !is_array($sitesResponse['body'])) {
        throw new RuntimeException('Could not list Search Console properties: ' . sitemap_cleanup_error_message($sitesResponse));
    }

    $siteEntries = is_array($sitesResponse['body']['siteEntry'] ?? null)
        ? $sitesResponse['body']['siteEntry']
        : [];
    $siteUrl = sitemap_cleanup_resolve_site_url($siteEntries);

    $listResponse = $api->request(
        'GET',
        'https://www.googleapis.com/webmasters/v3/sites/' . rawurlencode($siteUrl) . '/sitemaps'
    );
    if ($listResponse['status'] < 200 || $listResponse['status'] >= 300 || !is_array($listResponse['body'])) {
        throw new RuntimeException('Could not list Search Console sitemaps: ' . sitemap_cleanup_error_message($listResponse));
    }

    $entries = is_array($listResponse['body']['sitemap'] ?? null) ? $listResponse['body']['sitemap'] : [];
    $present = [];
    foreach ($entries as $entry) {
        if (!is_array($entry)) {
            continue;
        }
        $path = trim((string)($entry['path'] ?? ''));
        if ($path !== '') {
            $present[$path] = true;
        }
    }

    if (isset($present[GSC_CANONICAL_SITEMAP]) === false) {
        throw new RuntimeException('Canonical ShopVivaliz sitemap is not registered; refusing cleanup.');
    }

    if (isset($present[GSC_LEGACY_WWW_SITEMAP]) === false) {
        fwrite(STDOUT, json_encode([
            'siteUrl' => $siteUrl,
            'legacySitemap' => GSC_LEGACY_WWW_SITEMAP,
            'canonicalSitemap' => GSC_CANONICAL_SITEMAP,
            'changed' => false,
            'state' => 'already_absent',
        ], JSON_UNESCAPED_SLASHES) . PHP_EOL);
        exit(0);
    }

    $deleteResponse = $api->request(
        'DELETE',
        'https://www.googleapis.com/webmasters/v3/sites/'
            . rawurlencode($siteUrl)
            . '/sitemaps/'
            . rawurlencode(GSC_LEGACY_WWW_SITEMAP)
    );
    if ($deleteResponse['status'] < 200 || $deleteResponse['status'] >= 300) {
        throw new RuntimeException('Could not delete legacy www sitemap: ' . sitemap_cleanup_error_message($deleteResponse));
    }

    $verifyResponse = $api->request(
        'GET',
        'https://www.googleapis.com/webmasters/v3/sites/' . rawurlencode($siteUrl) . '/sitemaps'
    );
    if ($verifyResponse['status'] < 200 || $verifyResponse['status'] >= 300 || !is_array($verifyResponse['body'])) {
        throw new RuntimeException('Could not verify sitemap cleanup: ' . sitemap_cleanup_error_message($verifyResponse));
    }

    $legacyStillPresent = false;
    $canonicalStillPresent = false;
    foreach ((array)($verifyResponse['body']['sitemap'] ?? []) as $entry) {
        if (!is_array($entry)) {
            continue;
        }
        $path = trim((string)($entry['path'] ?? ''));
        if ($path === GSC_LEGACY_WWW_SITEMAP) {
            $legacyStillPresent = true;
        }
        if ($path === GSC_CANONICAL_SITEMAP) {
            $canonicalStillPresent = true;
        }
    }

    if ($legacyStillPresent || !$canonicalStillPresent) {
        throw new RuntimeException('Sitemap cleanup verification failed; expected final sitemap state was not observed.');
    }

    fwrite(STDOUT, json_encode([
        'siteUrl' => $siteUrl,
        'legacySitemap' => GSC_LEGACY_WWW_SITEMAP,
        'canonicalSitemap' => GSC_CANONICAL_SITEMAP,
        'changed' => true,
        'state' => 'removed',
    ], JSON_UNESCAPED_SLASHES) . PHP_EOL);
} catch (Throwable $error) {
    fwrite(STDERR, '[google-search-console-sitemap-cleanup] ' . $error->getMessage() . PHP_EOL);
    exit(1);
}

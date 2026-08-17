<?php
declare(strict_types=1);

/**
 * ShopVivaliz Search Console audit.
 *
 * Reads OAuth credentials from the existing runtime environment, discovers the
 * Search Console property when GOOGLE_SEARCH_CONSOLE_SITE_URL is not supplied,
 * reads the public sitemap, and inspects a bounded set of URLs.
 *
 * Examples:
 *   php scripts/google-search-console-audit.php --max-urls=100
 *   php scripts/google-search-console-audit.php --max-urls=200 --offset=200 --output=reports/gsc-audit.json
 */

$root = dirname(__DIR__);
$constants = $root . '/config/constants.php';
if (is_file($constants)) {
    require_once $constants; // Loads release .env and deploy shared/.env.
}

$autoload = $root . '/vendor/autoload.php';
if (is_file($autoload)) {
    require_once $autoload;
} else {
    require_once $root . '/src/Google/OAuthTokenProvider.php';
    require_once $root . '/src/Google/GoogleApiClient.php';
}

use RuntimeException;
use ShopVivaliz\Google\GoogleApiClient;
use ShopVivaliz\Google\OAuthTokenProvider;

function gsc_option(array $argv, string $name, ?string $default = null): ?string
{
    $prefix = '--' . $name . '=';
    foreach ($argv as $argument) {
        if (strpos($argument, $prefix) === 0) {
            return substr($argument, strlen($prefix));
        }
    }
    return $default;
}

function gsc_http_get(string $url): string
{
    if (!function_exists('curl_init')) {
        throw new RuntimeException('The cURL PHP extension is required.');
    }
    $ch = curl_init($url);
    if ($ch === false) {
        throw new RuntimeException('Could not initialize cURL.');
    }
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 5,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 45,
        CURLOPT_HTTPHEADER => [
            'Accept: application/xml,text/xml;q=0.9,*/*;q=0.8',
            'User-Agent: ShopVivaliz-SearchConsoleAudit/1.0',
        ],
    ]);
    $body = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    if ($body === false) {
        throw new RuntimeException('Sitemap transport error: ' . ($error !== '' ? $error : 'unknown cURL error') . '.');
    }
    if ($status < 200 || $status >= 300) {
        throw new RuntimeException('Sitemap returned HTTP ' . $status . '.');
    }
    return (string)$body;
}

/** @return array<int,string> */
function gsc_sitemap_urls(string $xml): array
{
    if (!preg_match_all('~<loc>\s*(.*?)\s*</loc>~is', $xml, $matches)) {
        return [];
    }
    $urls = [];
    foreach ($matches[1] as $raw) {
        $url = trim(html_entity_decode(strip_tags((string)$raw), ENT_QUOTES | ENT_XML1, 'UTF-8'));
        if ($url !== '' && filter_var($url, FILTER_VALIDATE_URL)) {
            $urls[$url] = true;
        }
    }
    return array_keys($urls);
}

/** @param array<int,array<string,mixed>> $entries */
function gsc_resolve_site_url(array $entries, string $preferred, string $baseUrl): string
{
    if ($preferred !== '') {
        foreach ($entries as $entry) {
            if ((string)($entry['siteUrl'] ?? '') === $preferred) {
                return $preferred;
            }
        }
        throw new RuntimeException('GOOGLE_SEARCH_CONSOLE_SITE_URL is not available to this OAuth user.');
    }

    $host = strtolower((string)parse_url($baseUrl, PHP_URL_HOST));
    $urlPrefixCandidates = [];
    $domainCandidate = '';
    foreach ($entries as $entry) {
        $siteUrl = trim((string)($entry['siteUrl'] ?? ''));
        if ($siteUrl === '') {
            continue;
        }
        if (strpos($siteUrl, 'sc-domain:') === 0) {
            if (strtolower(substr($siteUrl, strlen('sc-domain:'))) === $host) {
                $domainCandidate = $siteUrl;
            }
            continue;
        }
        $entryHost = strtolower((string)parse_url($siteUrl, PHP_URL_HOST));
        if ($entryHost === $host) {
            $urlPrefixCandidates[] = $siteUrl;
        }
    }

    // Prefer the most specific production URL-prefix property, then domain property.
    usort($urlPrefixCandidates, static function (string $a, string $b): int {
        return strlen($b) <=> strlen($a);
    });
    if ($urlPrefixCandidates !== []) {
        return $urlPrefixCandidates[0];
    }
    if ($domainCandidate !== '') {
        return $domainCandidate;
    }

    throw new RuntimeException('No Search Console property matching the ShopVivaliz production host was found.');
}

function gsc_normalize_url(string $url): string
{
    $url = trim($url);
    if ($url === '') {
        return '';
    }
    $parts = parse_url($url);
    if (!is_array($parts)) {
        return rtrim($url, '/');
    }
    $scheme = strtolower((string)($parts['scheme'] ?? 'https'));
    $host = strtolower((string)($parts['host'] ?? ''));
    $path = (string)($parts['path'] ?? '/');
    $query = isset($parts['query']) && $parts['query'] !== '' ? '?' . $parts['query'] : '';
    if ($path !== '/') {
        $path = rtrim($path, '/');
    }
    return $scheme . '://' . $host . ($path !== '' ? $path : '/') . $query;
}

function gsc_error_message(array $response): string
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

try {
    $baseUrl = rtrim((string)(gsc_option($argv, 'base-url', getenv('GOOGLE_SITE_BASE_URL') ?: 'https://shopvivaliz.com.br') ?: ''), '/');
    $preferredSiteUrl = trim((string)(gsc_option($argv, 'site-url', getenv('GOOGLE_SEARCH_CONSOLE_SITE_URL') ?: '') ?: ''));
    $sitemapUrl = trim((string)(gsc_option($argv, 'sitemap', getenv('GOOGLE_SEARCH_CONSOLE_SITEMAP_URL') ?: ($baseUrl . '/sitemap.xml')) ?: ''));
    $maxUrls = max(1, min(1900, (int)(gsc_option($argv, 'max-urls', getenv('GOOGLE_SEARCH_CONSOLE_AUDIT_MAX_URLS') ?: '100') ?: '100')));
    $offset = max(0, (int)(gsc_option($argv, 'offset', '0') ?: '0'));
    $output = trim((string)(gsc_option($argv, 'output', '') ?: ''));

    if ($baseUrl === '' || !filter_var($baseUrl, FILTER_VALIDATE_URL)) {
        throw new RuntimeException('Invalid --base-url / GOOGLE_SITE_BASE_URL.');
    }
    if ($sitemapUrl === '' || !filter_var($sitemapUrl, FILTER_VALIDATE_URL)) {
        throw new RuntimeException('Invalid --sitemap / GOOGLE_SEARCH_CONSOLE_SITEMAP_URL.');
    }

    $api = new GoogleApiClient(OAuthTokenProvider::fromEnvironment());

    $sitesResponse = $api->request('GET', 'https://www.googleapis.com/webmasters/v3/sites');
    if ($sitesResponse['status'] < 200 || $sitesResponse['status'] >= 300 || !is_array($sitesResponse['body'])) {
        throw new RuntimeException('Could not list Search Console properties: ' . gsc_error_message($sitesResponse));
    }
    $siteEntries = is_array($sitesResponse['body']['siteEntry'] ?? null) ? $sitesResponse['body']['siteEntry'] : [];
    $siteUrl = gsc_resolve_site_url($siteEntries, $preferredSiteUrl, $baseUrl);

    $registeredSitemapsResponse = $api->request(
        'GET',
        'https://www.googleapis.com/webmasters/v3/sites/' . rawurlencode($siteUrl) . '/sitemaps'
    );
    $registeredSitemaps = [];
    if ($registeredSitemapsResponse['status'] >= 200 && $registeredSitemapsResponse['status'] < 300) {
        $registeredSitemaps = is_array($registeredSitemapsResponse['body']['sitemap'] ?? null)
            ? $registeredSitemapsResponse['body']['sitemap']
            : [];
    }

    $allUrls = gsc_sitemap_urls(gsc_http_get($sitemapUrl));
    if ($allUrls === []) {
        throw new RuntimeException('The public sitemap did not contain any valid <loc> URLs.');
    }
    $selectedUrls = array_slice($allUrls, $offset, $maxUrls);

    $results = [];
    $issueCounts = [];
    foreach ($selectedUrls as $url) {
        $response = $api->request(
            'POST',
            'https://searchconsole.googleapis.com/v1/urlInspection/index:inspect',
            [
                'inspectionUrl' => $url,
                'siteUrl' => $siteUrl,
                'languageCode' => 'pt-BR',
            ]
        );

        if ($response['status'] < 200 || $response['status'] >= 300 || !is_array($response['body'])) {
            $issues = ['API_ERROR'];
            $entry = [
                'url' => $url,
                'httpStatus' => $response['status'],
                'issues' => $issues,
                'apiError' => gsc_error_message($response),
            ];
        } else {
            $index = $response['body']['inspectionResult']['indexStatusResult'] ?? [];
            $index = is_array($index) ? $index : [];
            $verdict = trim((string)($index['verdict'] ?? ''));
            $coverageState = trim((string)($index['coverageState'] ?? ''));
            $indexingState = trim((string)($index['indexingState'] ?? ''));
            $pageFetchState = trim((string)($index['pageFetchState'] ?? ''));
            $robotsTxtState = trim((string)($index['robotsTxtState'] ?? ''));
            $googleCanonical = trim((string)($index['googleCanonical'] ?? ''));
            $userCanonical = trim((string)($index['userCanonical'] ?? ''));

            $issues = [];
            if ($verdict !== '' && $verdict !== 'PASS') {
                $issues[] = 'INDEX_VERDICT_' . preg_replace('/[^A-Z0-9_]+/', '_', strtoupper($verdict));
            }
            if ($indexingState !== '' && $indexingState !== 'INDEXING_ALLOWED') {
                $issues[] = 'INDEXING_NOT_ALLOWED';
            }
            if ($pageFetchState !== '' && $pageFetchState !== 'SUCCESSFUL') {
                $issues[] = 'PAGE_FETCH_' . preg_replace('/[^A-Z0-9_]+/', '_', strtoupper($pageFetchState));
            }
            if ($robotsTxtState !== '' && $robotsTxtState !== 'ALLOWED') {
                $issues[] = 'ROBOTS_' . preg_replace('/[^A-Z0-9_]+/', '_', strtoupper($robotsTxtState));
            }
            if (
                $googleCanonical !== ''
                && $userCanonical !== ''
                && gsc_normalize_url($googleCanonical) !== gsc_normalize_url($userCanonical)
            ) {
                $issues[] = 'CANONICAL_MISMATCH';
            }

            $entry = [
                'url' => $url,
                'httpStatus' => $response['status'],
                'issues' => array_values(array_unique($issues)),
                'verdict' => $verdict,
                'coverageState' => $coverageState,
                'indexingState' => $indexingState,
                'pageFetchState' => $pageFetchState,
                'robotsTxtState' => $robotsTxtState,
                'lastCrawlTime' => (string)($index['lastCrawlTime'] ?? ''),
                'googleCanonical' => $googleCanonical,
                'userCanonical' => $userCanonical,
                'crawledAs' => (string)($index['crawledAs'] ?? ''),
                'sitemap' => is_array($index['sitemap'] ?? null) ? $index['sitemap'] : [],
                'referringUrls' => is_array($index['referringUrls'] ?? null) ? $index['referringUrls'] : [],
            ];
        }

        foreach ($entry['issues'] as $issue) {
            $issueCounts[$issue] = ($issueCounts[$issue] ?? 0) + 1;
        }
        $results[] = $entry;

        // URL Inspection currently allows 600 queries/minute per property.
        // 150ms keeps this CLI comfortably below that ceiling.
        usleep(150000);
    }

    arsort($issueCounts);
    $report = [
        'generatedAt' => gmdate('c'),
        'siteUrl' => $siteUrl,
        'sitemapUrl' => $sitemapUrl,
        'sitemapUrlCount' => count($allUrls),
        'offset' => $offset,
        'inspectedCount' => count($results),
        'cleanCount' => count(array_filter($results, static fn(array $row): bool => $row['issues'] === [])),
        'issueCount' => count(array_filter($results, static fn(array $row): bool => $row['issues'] !== [])),
        'issueCounts' => $issueCounts,
        'registeredSitemaps' => $registeredSitemaps,
        'results' => $results,
    ];

    $json = json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) {
        throw new RuntimeException('Could not encode Search Console audit report.');
    }

    if ($output !== '') {
        $directory = dirname($output);
        if ($directory !== '.' && !is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
            throw new RuntimeException('Could not create output directory: ' . $directory);
        }
        if (file_put_contents($output, $json . PHP_EOL) === false) {
            throw new RuntimeException('Could not write audit report: ' . $output);
        }
        fwrite(STDOUT, json_encode([
            'generatedAt' => $report['generatedAt'],
            'siteUrl' => $report['siteUrl'],
            'sitemapUrlCount' => $report['sitemapUrlCount'],
            'inspectedCount' => $report['inspectedCount'],
            'cleanCount' => $report['cleanCount'],
            'issueCount' => $report['issueCount'],
            'issueCounts' => $report['issueCounts'],
            'output' => $output,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL);
    } else {
        fwrite(STDOUT, $json . PHP_EOL);
    }

    exit($report['issueCount'] > 0 ? 2 : 0);
} catch (Throwable $error) {
    // Never print request headers, access tokens, refresh tokens, or raw API bodies.
    fwrite(STDERR, '[google-search-console-audit] ' . $error->getMessage() . PHP_EOL);
    exit(1);
}

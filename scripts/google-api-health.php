<?php
declare(strict_types=1);

/**
 * Read-only Google API connectivity check for ShopVivaliz.
 *
 * Checks OAuth refresh plus accessible resources for Search Console, GTM and
 * Merchant API v1. GA4 is checked when GOOGLE_GA4_PROPERTY_ID is configured.
 * No service is mutated and no credential/token is printed.
 */

$root = dirname(__DIR__);
if (is_file($root . '/config/constants.php')) {
    require_once $root . '/config/constants.php';
}
if (is_file($root . '/vendor/autoload.php')) {
    require_once $root . '/vendor/autoload.php';
} else {
    require_once $root . '/src/Google/OAuthTokenProvider.php';
    require_once $root . '/src/Google/GoogleApiClient.php';
}

use ShopVivaliz\Google\GoogleApiClient;
use ShopVivaliz\Google\OAuthTokenProvider;

function gah_error(array $response): string
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

function gah_result(string $service, array $response, callable $summarize): array
{
    $status = (int)($response['status'] ?? 0);
    if ($status < 200 || $status >= 300 || !is_array($response['body'] ?? null)) {
        return [
            'service' => $service,
            'ok' => false,
            'httpStatus' => $status,
            'message' => gah_error($response),
        ];
    }
    return [
        'service' => $service,
        'ok' => true,
        'httpStatus' => $status,
        'summary' => $summarize($response['body']),
    ];
}

try {
    $tokens = OAuthTokenProvider::fromEnvironment();
    // Force a refresh so this command is a real credentials/refresh-token check.
    $tokens->getAccessToken(true);
    $api = new GoogleApiClient($tokens);
    $checks = [];

    $searchConsole = $api->request('GET', 'https://www.googleapis.com/webmasters/v3/sites');
    $checks[] = gah_result('search_console', $searchConsole, static function (array $body): array {
        $entries = is_array($body['siteEntry'] ?? null) ? $body['siteEntry'] : [];
        return [
            'propertyCount' => count($entries),
            'properties' => array_values(array_filter(array_map(
                static fn($entry): string => is_array($entry) ? trim((string)($entry['siteUrl'] ?? '')) : '',
                $entries
            ))),
        ];
    });

    $gtm = $api->request('GET', 'https://tagmanager.googleapis.com/tagmanager/v2/accounts');
    $checks[] = gah_result('tag_manager', $gtm, static function (array $body): array {
        $accounts = is_array($body['account'] ?? null) ? $body['account'] : [];
        return [
            'accountCount' => count($accounts),
            'accounts' => array_map(static function ($account): array {
                return is_array($account) ? [
                    'accountId' => (string)($account['accountId'] ?? ''),
                    'name' => (string)($account['name'] ?? ''),
                ] : [];
            }, $accounts),
        ];
    });

    // Merchant API v1 is intentional. Do not reintroduce Content API endpoints.
    $merchant = $api->request('GET', 'https://merchantapi.googleapis.com/accounts/v1/accounts?pageSize=50');
    $checks[] = gah_result('merchant_api_v1', $merchant, static function (array $body): array {
        $accounts = is_array($body['accounts'] ?? null) ? $body['accounts'] : [];
        return [
            'accountCount' => count($accounts),
            'accounts' => array_map(static function ($account): array {
                return is_array($account) ? [
                    'name' => (string)($account['name'] ?? ''),
                    'accountName' => (string)($account['accountName'] ?? ''),
                ] : [];
            }, $accounts),
        ];
    });

    $ga4PropertyId = trim((string)(getenv('GOOGLE_GA4_PROPERTY_ID') ?: ''));
    if ($ga4PropertyId !== '') {
        $ga4 = $api->request(
            'POST',
            'https://analyticsdata.googleapis.com/v1beta/properties/' . rawurlencode($ga4PropertyId) . ':runReport',
            [
                'dateRanges' => [['startDate' => '7daysAgo', 'endDate' => 'yesterday']],
                'metrics' => [['name' => 'eventCount']],
                'limit' => 1,
            ]
        );
        $checks[] = gah_result('ga4_data_api', $ga4, static function (array $body) use ($ga4PropertyId): array {
            return [
                'propertyId' => $ga4PropertyId,
                'rowCount' => (int)($body['rowCount'] ?? 0),
            ];
        });
    } else {
        $checks[] = [
            'service' => 'ga4_data_api',
            'ok' => null,
            'skipped' => true,
            'message' => 'Set GOOGLE_GA4_PROPERTY_ID (numeric property ID) to run the read-only GA4 check.',
        ];
    }

    // Indexing API is deliberately not probed with a ShopVivaliz product/blog URL.
    // Google restricts that API to JobPosting or BroadcastEvent-in-VideoObject pages.
    $checks[] = [
        'service' => 'indexing_api',
        'ok' => null,
        'skipped' => true,
        'message' => 'No generic URL submitted. Indexing API is restricted to eligible JobPosting/BroadcastEvent pages.',
    ];

    $failed = count(array_filter($checks, static fn(array $check): bool => ($check['ok'] ?? null) === false));
    $report = [
        'generatedAt' => gmdate('c'),
        'oauthRefresh' => 'ok',
        'failedChecks' => $failed,
        'checks' => $checks,
    ];
    fwrite(STDOUT, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL);
    exit($failed > 0 ? 2 : 0);
} catch (Throwable $error) {
    fwrite(STDERR, '[google-api-health] ' . $error->getMessage() . PHP_EOL);
    exit(1);
}

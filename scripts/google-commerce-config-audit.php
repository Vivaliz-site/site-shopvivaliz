<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}

$root = rtrim((string)(getenv('SHOPVIVALIZ_ROOT') ?: dirname(__DIR__)), DIRECTORY_SEPARATOR);
require_once $root . '/config/bootstrap-env.php';

function gc_bool_env(array $names): bool
{
    foreach ($names as $name) {
        $value = trim((string)(getenv($name) ?: ''));
        if ($value !== '' && !str_contains($value, 'YOUR_') && !str_contains($value, 'XXXXXXXX')) {
            return true;
        }
    }
    return false;
}

function gc_fetch(string $url): array
{
    $handle = curl_init($url);
    curl_setopt_array($handle, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_CONNECTTIMEOUT => 10,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_HTTPHEADER => ['Accept: text/html,application/xml;q=0.9,*/*;q=0.8'],
        CURLOPT_USERAGENT => 'ShopVivaliz-Google-Commerce-Audit/1.1',
    ]);
    $body = curl_exec($handle);
    $status = (int)curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
    $contentType = (string)curl_getinfo($handle, CURLINFO_CONTENT_TYPE);
    $error = curl_errno($handle);
    curl_close($handle);
    return [
        'ok' => $error === 0 && $status >= 200 && $status < 300,
        'status' => $status,
        'content_type' => $contentType,
        'body' => is_string($body) ? $body : '',
    ];
}

function gc_result(bool $ok, string $status, string $detail = '', string $level = 'required'): array
{
    return ['ok' => $ok, 'status' => $status, 'detail' => $detail, 'level' => $level];
}

function gc_has_any(string $haystack, array $needles): bool
{
    foreach ($needles as $needle) {
        if (str_contains($haystack, $needle)) return true;
    }
    return false;
}

$baseUrl = rtrim((string)(getenv('SHOPVIVALIZ_BASE_URL') ?: 'https://shopvivaliz.com.br'), '/');
$home = gc_fetch($baseUrl . '/?google_config_audit=2');
$sitemap = gc_fetch($baseUrl . '/sitemap.xml?google_config_audit=2');
$merchant = gc_fetch($baseUrl . '/google-merchant-feed.php?google_config_audit=2');
$homeBody = (string)$home['body'];

$ga4BrowserPublished = gc_has_any($homeBody, [
    'googletagmanager.com/gtag/js?id=G-',
    "gtag('config', 'G-",
    'gtag("config", "G-',
]);
$gtmPublished = str_contains($homeBody, 'googletagmanager.com/gtm.js');
$adsPublished = gc_has_any($homeBody, [
    'googletagmanager.com/gtag/js?id=AW-',
    "gtag('config', 'AW-",
    'gtag("config", "AW-',
]);
$ga4SecretConfigured = gc_bool_env(['GA4_SECRET']);
$adsLabelConfigured = gc_bool_env(['GOOGLE_ADS_CONVERSION_LABEL']);
$searchVerificationConfigured = gc_bool_env(['GOOGLE_SITE_VERIFICATION']);
$tinyClientConfigured = gc_bool_env([
    'TINY_CLIENT_ID', 'TINY_CLIENT_ID', 'TINY_OAUTH_CLIENT_ID', 'TINY_TOKEN', 'TINY_API_TOKEN',
]);
$tinySecretConfigured = gc_bool_env([
    'TINY_CLIENT_SECRET', 'TINY_CLIENT_SECRET', 'TINY_OAUTH_CLIENT_SECRET', 'TINY_TOKEN', 'TINY_API_TOKEN',
]);
$tinyRefreshConfigured = gc_bool_env(['TINY_REFRESH_TOKEN', 'TINY_REFRESH_TOKEN', 'TINY_TOKEN', 'TINY_API_TOKEN']);

$checks = [
    'production_home' => gc_result((bool)$home['ok'], $home['ok'] ? 'configured' : 'failed', 'HTTP ' . $home['status']),
    'ga4_browser_tag' => gc_result(
        $ga4BrowserPublished,
        $ga4BrowserPublished ? 'published' : 'missing_from_production_html',
        'Validated from rendered production HTML; environment variables are not required when a reviewed fallback ID is published.'
    ),
    'ga4_server_purchase' => gc_result(
        $ga4BrowserPublished && $ga4SecretConfigured,
        $ga4SecretConfigured ? 'configured' : 'measurement_protocol_secret_missing',
        $ga4SecretConfigured
            ? 'Approved purchases can be sent server-side.'
            : 'Browser funnel remains active; create GA4_SECRET to enable approved-payment purchase delivery from the server.',
        'recommended'
    ),
    'google_tag_manager' => gc_result(
        $gtmPublished,
        $gtmPublished ? 'published' : 'not_published',
        'Validated from rendered production HTML.',
        'recommended'
    ),
    'google_ads_tag' => gc_result(
        $adsPublished,
        $adsPublished ? 'published' : 'not_published',
        $adsLabelConfigured ? 'Conversion label configured.' : 'No production Ads tag/label was proven; linked-account evidence must be reviewed in Google Ads.',
        'recommended'
    ),
    'consent_mode_v2' => gc_result(
        str_contains($homeBody, "gtag('consent', 'default'")
            && str_contains($homeBody, 'ad_user_data')
            && str_contains($homeBody, 'ad_personalization'),
        'production_html_checked'
    ),
    'search_console_verification_meta' => gc_result(
        $searchVerificationConfigured && str_contains($homeBody, 'google-site-verification'),
        $searchVerificationConfigured ? 'meta_configured' : 'not_present_or_dns_verified',
        'Domain-property ownership may be verified by DNS and cannot be inferred from HTML alone.',
        'informational'
    ),
    'sitemap' => gc_result(
        (bool)$sitemap['ok'] && str_contains((string)$sitemap['body'], '<urlset'),
        $sitemap['ok'] ? 'available' : 'failed',
        'HTTP ' . $sitemap['status']
    ),
    'merchant_feed' => gc_result(
        (bool)$merchant['ok'] && str_contains((string)$merchant['body'], 'xmlns:g="http://base.google.com/ns/1.0"'),
        $merchant['ok'] ? 'available' : 'failed',
        'HTTP ' . $merchant['status']
    ),
    'smtp_order_email' => gc_result(
        gc_bool_env(['SMTP_HOST', 'EMAIL_SMTP_HOST', 'MAIL_HOST'])
            && gc_bool_env(['SMTP_USER', 'EMAIL_USER', 'MAIL_USER'])
            && gc_bool_env(['SMTP_PASS', 'EMAIL_PASSWORD', 'MAIL_PASS']),
        'runtime_presence_checked'
    ),
    'mercadopago_boleto' => gc_result(
        gc_bool_env(['MERCADOPAGO_ACCESS_TOKEN'])
            && gc_bool_env(['MERCADOPAGO_PUBLIC_KEY'])
            && gc_bool_env(['MERCADOPAGO_WEBHOOK_SECRET']),
        'runtime_presence_checked'
    ),
    'tiny_erp' => gc_result(
        $tinyClientConfigured && $tinySecretConfigured && $tinyRefreshConfigured,
        'runtime_presence_checked',
        'Accepts the current OLIST_* OAuth names and legacy TINY_* names; values are never printed.'
    ),
];

$blockerKeys = [
    'production_home', 'ga4_browser_tag', 'consent_mode_v2',
    'sitemap', 'merchant_feed', 'smtp_order_email', 'mercadopago_boleto', 'tiny_erp',
];
$blockers = [];
$recommendations = [];
foreach ($checks as $key => $check) {
    if (in_array($key, $blockerKeys, true) && empty($check['ok'])) {
        $blockers[] = $key;
    } elseif (($check['level'] ?? '') === 'recommended' && empty($check['ok'])) {
        $recommendations[] = $key;
    }
}

$output = [
    'ok' => $blockers === [],
    'generated_at' => date(DATE_ATOM),
    'base_url' => $baseUrl,
    'checks' => $checks,
    'blockers' => $blockers,
    'recommendations' => $recommendations,
    'notes' => [
        'No secret values, tag IDs, cookies or personal data are included.',
        'Search Console DNS ownership and account linkages require console/API evidence and are not inferred from HTML.',
        'Google Ads campaign status, budgets and spend are not changed by this audit.',
        'Optional capabilities are reported separately and do not mask failures in required commerce integrations.',
    ],
];

echo json_encode($output, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($output['ok'] ? 0 : 1);

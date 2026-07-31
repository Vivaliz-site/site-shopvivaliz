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
        CURLOPT_USERAGENT => 'ShopVivaliz-Google-Commerce-Audit/1.0',
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

function gc_result(bool $ok, string $status, string $detail = ''): array
{
    return ['ok' => $ok, 'status' => $status, 'detail' => $detail];
}

$baseUrl = rtrim((string)(getenv('SHOPVIVALIZ_BASE_URL') ?: 'https://shopvivaliz.com.br'), '/');
$home = gc_fetch($baseUrl . '/?google_config_audit=1');
$sitemap = gc_fetch($baseUrl . '/sitemap.xml?google_config_audit=1');
$merchant = gc_fetch($baseUrl . '/google-merchant-feed.php?google_config_audit=1');
$homeBody = (string)$home['body'];

$ga4Configured = gc_bool_env(['GA4_ID', 'GOOGLE_ANALYTICS_ID', 'GOOGLE_ANALYTICS', 'GOOGLE_ANALITYCS']);
$ga4SecretConfigured = gc_bool_env(['GA4_SECRET']);
$gtmConfigured = gc_bool_env(['GOOGLE_TAG_MANAGER_ID', 'GTM_ID', 'TAG_MANAGER']);
$adsConfigured = gc_bool_env(['GOOGLE_ADS_ID', 'GOOGLE_ADS_CONVERSION_ID']);
$adsLabelConfigured = gc_bool_env(['GOOGLE_ADS_CONVERSION_LABEL']);
$searchVerificationConfigured = gc_bool_env(['GOOGLE_SITE_VERIFICATION']);

$checks = [
    'production_home' => gc_result((bool)$home['ok'], $home['ok'] ? 'configured' : 'failed', 'HTTP ' . $home['status']),
    'ga4_browser_tag' => gc_result(
        $ga4Configured && (str_contains($homeBody, 'googletagmanager.com/gtag/js') || str_contains($homeBody, "gtag('config'")),
        $ga4Configured ? 'configured' : 'missing_runtime_configuration'
    ),
    'ga4_server_purchase' => gc_result(
        $ga4Configured && $ga4SecretConfigured,
        $ga4Configured && $ga4SecretConfigured ? 'configured' : 'missing_runtime_configuration'
    ),
    'google_tag_manager' => gc_result(
        $gtmConfigured && str_contains($homeBody, 'googletagmanager.com/gtm.js'),
        $gtmConfigured ? 'configured' : 'missing_runtime_configuration'
    ),
    'google_ads_tag' => gc_result(
        $adsConfigured && str_contains($homeBody, "gtag('config', 'AW-"),
        $adsConfigured ? 'configured' : 'missing_runtime_configuration',
        $adsLabelConfigured ? 'conversion label configured' : 'conversion label not configured'
    ),
    'consent_mode_v2' => gc_result(
        str_contains($homeBody, "gtag('consent', 'default'")
            && str_contains($homeBody, 'ad_user_data')
            && str_contains($homeBody, 'ad_personalization'),
        'code_checked'
    ),
    'search_console_verification_meta' => gc_result(
        $searchVerificationConfigured && str_contains($homeBody, 'google-site-verification'),
        $searchVerificationConfigured ? 'meta_configured' : 'not_present_or_dns_verified',
        'DNS ownership cannot be proven from source code alone'
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
        gc_bool_env(['TINY_CLIENT_ID', 'TINY_OAUTH_CLIENT_ID', 'TINY_TOKEN', 'TINY_API_TOKEN'])
            && gc_bool_env(['TINY_CLIENT_SECRET', 'TINY_OAUTH_CLIENT_SECRET', 'TINY_TOKEN', 'TINY_API_TOKEN']),
        'runtime_presence_checked'
    ),
];

$blockerKeys = [
    'production_home', 'ga4_browser_tag', 'ga4_server_purchase', 'consent_mode_v2',
    'sitemap', 'merchant_feed', 'smtp_order_email', 'mercadopago_boleto', 'tiny_erp',
];
$blockers = [];
foreach ($blockerKeys as $key) {
    if (empty($checks[$key]['ok'])) {
        $blockers[] = $key;
    }
}

$output = [
    'ok' => $blockers === [],
    'generated_at' => date(DATE_ATOM),
    'base_url' => $baseUrl,
    'checks' => $checks,
    'blockers' => $blockers,
    'notes' => [
        'No secret values, IDs, cookies or personal data are included.',
        'Search Console DNS ownership and account linkages require console/API evidence and are not inferred from HTML.',
        'Google Ads campaign status and spend are not changed by this audit.',
    ],
];

echo json_encode($output, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit($output['ok'] ? 0 : 1);

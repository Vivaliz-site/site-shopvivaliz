<?php
declare(strict_types=1);

function gav5_assert(bool $ok, string $message): void
{
    if (!$ok) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$root = dirname(__DIR__);
$sales = (string)file_get_contents($root . '/js/sales-conversion-v1.js');
$analytics = (string)file_get_contents($root . '/includes/analytics-tracking.php');
$head = (string)file_get_contents($root . '/includes/head-analytics.php');
$admin = (string)file_get_contents($root . '/admin/integrations.php');

gav5_assert(!str_contains($sales, 'compras acima de R$ 100'), 'cart offer must not invent a R$100 minimum for VIVALIZ10');
gav5_assert(!str_contains($sales, 'carrinho passar de R$ 100'), 'product offer must not invent a R$100 minimum for VIVALIZ10');
gav5_assert(str_contains($sales, 'VIVALIZ10'), 'active public coupon must remain visible in conversion copy');
gav5_assert(!str_contains($analytics, 'GTM-PHZ55CP3'), 'analytics runtime must not hard-code the legacy GTM container');
gav5_assert(!str_contains($head, 'GTM-PHZ55CP3'), 'head analytics must not hard-code the legacy GTM container');
gav5_assert(!str_contains($admin, 'GTM-PHZ55CP3'), 'admin integration status must not report the legacy GTM as a default');

putenv('GOOGLE_TAG_MANAGER_ID');
putenv('GTM_ID');
putenv('TAG_MANAGER');
require_once $root . '/includes/analytics-tracking.php';
$directTracking = $GLOBALS['analytics']->getTrackingCode();
gav5_assert(!str_contains($directTracking, 'googletagmanager.com/gtm.js'), 'GTM must stay disabled when no container is explicitly configured');
gav5_assert(str_contains($directTracking, 'googletagmanager.com/gtag/js?id=G-1H55K1TZ5D'), 'official GA4 direct loader must remain available without GTM');

putenv('GOOGLE_TAG_MANAGER_ID=GTM-TEST123');
$explicitGtm = new AnalyticsTracking();
$explicitTracking = $explicitGtm->getTrackingCode();
gav5_assert(str_contains($explicitTracking, 'GTM-TEST123'), 'explicit GTM configuration must still be honored');

// An explicitly configured retired container must not be revived by aliases.
$gtmKeys = ['GOOGLE_TAG_MANAGER_ID', 'GTM_ID', 'TAG_MANAGER'];
foreach ($gtmKeys as $selectedKey) {
    foreach ($gtmKeys as $key) { putenv($key); }
    putenv($selectedKey . '=GTM-PHZ55CP3');
    $retiredTracking = (new AnalyticsTracking())->getTrackingCode();
    gav5_assert(!str_contains($retiredTracking, 'googletagmanager.com/gtm.js'), 'retired GTM must not load through ' . $selectedKey);
    gav5_assert(str_contains($retiredTracking, 'googletagmanager.com/gtag/js?id=G-1H55K1TZ5D'), 'official GA4 must survive retired ' . $selectedKey);
}
foreach ($gtmKeys as $key) { putenv($key); }
foreach (['  GTM-PHZ55CP3  ', 'bad-container', "GTM-TEST123';alert(1);//"] as $invalidId) {
    putenv('GOOGLE_TAG_MANAGER_ID=' . $invalidId);
    $invalidTracking = (new AnalyticsTracking())->getTrackingCode();
    gav5_assert(!str_contains($invalidTracking, 'googletagmanager.com/gtm.js'), 'retired or malformed GTM must fail closed');
}
putenv('GOOGLE_TAG_MANAGER_ID=GTM-PHZ55CP3');
putenv('GTM_ID=GTM-TEST123');
$precedenceTracking = (new AnalyticsTracking())->getTrackingCode();
gav5_assert(!str_contains($precedenceTracking, 'googletagmanager.com/gtm.js'), 'retired primary must not select a lower-priority alias');
putenv('GTM_ID');
$GLOBALS['analytics'] = new AnalyticsTracking();
$_SERVER['REQUEST_URI'] = '/tracking-regression';
ob_start();
require $root . '/includes/head-analytics.php';
$renderedHead = (string)ob_get_clean();
gav5_assert(!str_contains($renderedHead, 'googletagmanager.com/gtm.js'), 'public head must reject retired GTM');
gav5_assert(str_contains($renderedHead, 'googletagmanager.com/gtag/js?id=G-1H55K1TZ5D'), 'public head must preserve official direct GA4');
putenv('GOOGLE_TAG_MANAGER_ID');

fwrite(STDOUT, "google_ads_v5_regression=ok\n");

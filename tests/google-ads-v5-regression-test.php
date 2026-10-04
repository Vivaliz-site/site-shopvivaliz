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

fwrite(STDOUT, "google_ads_v5_regression=ok\n");

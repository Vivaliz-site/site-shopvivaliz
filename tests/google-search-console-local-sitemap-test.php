<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$helper = $root . '/scripts/lib/google_search_console_sitemap.php';
if (!is_file($helper)) {
    fwrite(STDERR, "missing sitemap helper\n");
    exit(1);
}
require_once $helper;

$tmp = sys_get_temp_dir() . '/shopvivaliz-gsc-' . bin2hex(random_bytes(4));
mkdir($tmp . '/reports', 0777, true);
$xml = '<?xml version="1.0"?><urlset><url><loc>https://shopvivaliz.com.br/</loc></url></urlset>';
file_put_contents($tmp . '/reports/sitemap.xml', $xml);

$resolved = shopvivaliz_gsc_safe_sitemap_file($tmp, 'reports/sitemap.xml');
if ($resolved !== $tmp . '/reports/sitemap.xml') {
    fwrite(STDERR, "safe sitemap path mismatch\n");
    exit(1);
}
if (shopvivaliz_gsc_read_sitemap_file($tmp, 'reports/sitemap.xml') !== $xml) {
    fwrite(STDERR, "sitemap contents mismatch\n");
    exit(1);
}
foreach (['../sitemap.xml', 'sitemap.xml', 'reports/../sitemap.xml', '/tmp/sitemap.xml', 'reports/sitemap.json'] as $unsafe) {
    try {
        shopvivaliz_gsc_safe_sitemap_file($tmp, $unsafe);
        fwrite(STDERR, "unsafe sitemap path accepted: {$unsafe}\n");
        exit(1);
    } catch (RuntimeException $expected) {
    }
}
@unlink($tmp . '/reports/sitemap.xml');
@rmdir($tmp . '/reports');
@rmdir($tmp);
echo "google-search-console-local-sitemap-test: ok\n";

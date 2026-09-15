<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/includes/catalog-runtime.php';
$rows = [
    ['id'=>'1','sku'=>'JVCDPA34','name'=>'Canonical','slug'=>'canonical-jvcdpa34','price'=>355.81,'stock'=>9],
    ['id'=>'2','sku'=>'JVCDPA34','name'=>'Duplicate','slug'=>'duplicate-jvcdpa34','price'=>175.00,'stock'=>1],
    ['id'=>'3','sku'=>'OTHER1','name'=>'Other','slug'=>'other-other1','price'=>10.00,'stock'=>1],
];
$out = svcr_select_catalog_products($rows, []);
if (count($out) !== 2) { fwrite(STDERR, "FAIL: duplicate SKU must collapse to one canonical row\n"); exit(1); }
$matches = array_values(array_filter($out, static fn(array $p): bool => strtoupper((string)($p['sku'] ?? '')) === 'JVCDPA34'));
if (count($matches) !== 1 || (string)$matches[0]['id'] !== '1') {
    fwrite(STDERR, "FAIL: first canonical ERP registration must win duplicate SKU resolution\n"); exit(1);
}
fwrite(STDOUT, "PASS: catalog duplicate SKU contract.\n");

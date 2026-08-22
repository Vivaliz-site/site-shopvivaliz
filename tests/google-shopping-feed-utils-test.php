<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/google-shopping-feed-utils.php';

function svgf_test_assert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

svgf_test_assert(
    svgf_normalize_gtin('7894162000861') === '7894162000861',
    'Valid GTIN-13 should be preserved.'
);
svgf_test_assert(
    svgf_normalize_gtin('7894162000862') === '',
    'Invalid GTIN checksum should be rejected.'
);
svgf_test_assert(
    svgf_normalize_gtin('7894-1620-0086-1') === '7894162000861',
    'Formatting characters should be ignored for a valid GTIN.'
);

$identifiers = svgf_identifiers_from_row([
    'sku' => 'ARM-08',
    'brand' => 'Fercar',
    'gtin' => '7894162000861',
]);
svgf_test_assert($identifiers['brand'] === 'Fercar', 'Catalog brand should be retained.');
svgf_test_assert($identifiers['gtin'] === '7894162000861', 'Catalog GTIN should be retained.');
svgf_test_assert($identifiers['mpn'] === '', 'Store SKU must not be invented as an MPN.');

$temporaryRoot = sys_get_temp_dir() . '/svgf-' . bin2hex(random_bytes(6));
mkdir($temporaryRoot . '/storage', 0777, true);
file_put_contents(
    $temporaryRoot . '/storage/products-cache-ativos.json',
    json_encode([
        'data' => [
            'items' => [[
                'codigo' => 'TEST-SKU',
                'marca' => ['nome' => 'Marca Teste'],
                'ean' => '7894162000861',
                'manufacturer_part_number' => 'MPN-REAL-01',
            ]],
        ],
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
);

$map = svgf_catalog_identifier_map($temporaryRoot);
svgf_test_assert(isset($map['TEST-SKU']), 'Nested catalog payload should be indexed by SKU.');
svgf_test_assert(($map['TEST-SKU']['brand'] ?? '') === 'Marca Teste', 'Nested brand should be recovered.');
svgf_test_assert(($map['TEST-SKU']['gtin'] ?? '') === '7894162000861', 'Nested GTIN should be recovered.');
svgf_test_assert(($map['TEST-SKU']['mpn'] ?? '') === 'MPN-REAL-01', 'Real manufacturer MPN should be recovered.');

@unlink($temporaryRoot . '/storage/products-cache-ativos.json');
@rmdir($temporaryRoot . '/storage');
@rmdir($temporaryRoot);

svgf_test_assert(svgf_xml('A&B <C>') === 'A&amp;B &lt;C&gt;', 'XML escaping should be safe.');


$erpTinyImage = svgf_feed_absolute_url('https://shopvivaliz.com.br', 'https://s3.amazonaws.com/tiny-anexos-us/erp/teste.jpg');
svgf_test_assert($erpTinyImage === 'https://s3.amazonaws.com/tiny-anexos-us/erp/teste.jpg', 'ERP/Tiny S3 image host must be allowed for Merchant images.');
$unsafeS3 = svgf_feed_absolute_url('https://shopvivaliz.com.br', 'https://s3.amazonaws.com/other-bucket/teste.jpg');
svgf_test_assert($unsafeS3 === '', 'Unrelated S3 buckets must remain blocked.');

fwrite(STDOUT, "PASS: Google Shopping feed utility tests.\n");

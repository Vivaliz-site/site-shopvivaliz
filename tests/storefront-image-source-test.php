<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/storefront-image-source.php';

function assert_same(string $expected, string $actual, string $label): void
{
    if ($expected !== $actual) {
        fwrite(STDERR, "FAIL {$label}: expected {$expected}, got {$actual}\n");
        exit(1);
    }
}

$root = sys_get_temp_dir() . '/shopvivaliz-image-source-' . bin2hex(random_bytes(4));
mkdir($root . '/uploads/olist/SKU', 0775, true);
file_put_contents($root . '/uploads/olist/SKU/live.jpg', 'fixture');

try {
    assert_same(
        'https://s3.amazonaws.com/tiny-anexos-us/erp/example.jpg',
        svsis_resolve_image_url([
            'original_url_olist' => 'https://s3.amazonaws.com/tiny-anexos-us/erp/example.jpg',
            'site_url' => 'https://shopvivaliz.com.br/uploads/olist/SKU/missing.jpg',
        ], $root),
        'remote ERP source wins over stale ShopVivaliz upload'
    );

    assert_same(
        'https://shopvivaliz.com.br/uploads/olist/SKU/live.jpg',
        svsis_resolve_image_url([
            'site_url' => 'https://shopvivaliz.com.br/uploads/olist/SKU/live.jpg',
        ], $root),
        'existing local ShopVivaliz image remains usable'
    );

    assert_same(
        '',
        svsis_resolve_image_url([
            'site_url' => 'https://shopvivaliz.com.br/uploads/olist/SKU/missing.jpg',
            'local_url' => '/uploads/olist/SKU/also-missing.jpg',
        ], $root),
        'missing local-only image fails closed'
    );

    assert_same(
        'https://cdn.example.com/product.jpg',
        svsis_resolve_image_url([
            'site_url' => 'https://cdn.example.com/product.jpg',
        ], $root),
        'external fallback URL is accepted'
    );

    echo "OK storefront image source fallback\n";
} finally {
    @unlink($root . '/uploads/olist/SKU/live.jpg');
    @rmdir($root . '/uploads/olist/SKU');
    @rmdir($root . '/uploads/olist');
    @rmdir($root . '/uploads');
    @rmdir($root);
}

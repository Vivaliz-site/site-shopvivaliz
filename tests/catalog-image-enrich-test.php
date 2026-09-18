<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/catalog-image-enrich.php';

function svcie_test_assert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$products = [
    [
        'sku' => 'C06PT',
        'image_url' => '',
        'images' => [],
        'images_count' => 0,
        'price' => 1499.00,
        'stock' => 19,
    ],
    [
        'sku' => 'KEEP',
        'image_url' => 'https://erp.example/original.jpg',
        'images' => ['https://erp.example/original.jpg'],
        'images_count' => 1,
        'price' => 50.00,
        'stock' => 3,
    ],
];

$result = svcie_apply_image_map($products, [
    'C06PT' => ['https://erp.example/c06pt.jpg'],
    'KEEP' => [
        'https://erp.example/keep-primary.jpg',
        'https://erp.example/keep-secondary.jpg',
    ],
]);

svcie_test_assert(
    ($result[0]['image_url'] ?? '') === 'https://erp.example/c06pt.jpg',
    'Missing ERP image should be filled from the local mirror.'
);
svcie_test_assert(
    ($result[0]['images'][0] ?? '') === 'https://erp.example/c06pt.jpg',
    'Filled primary image should also seed the gallery used by catalog cards.'
);
svcie_test_assert(
    (float)($result[0]['price'] ?? 0) === 1499.00 && (int)($result[0]['stock'] ?? 0) === 19,
    'Image enrichment must not change ERP-authoritative price or stock.'
);
svcie_test_assert(
    ($result[1]['image_url'] ?? '') === 'https://erp.example/keep-primary.jpg',
    'ERP product media map must remain authoritative for the storefront primary image.'
);

svcie_test_assert(
    ($result[1]['images'] ?? []) === [
        'https://erp.example/keep-primary.jpg',
        'https://erp.example/keep-secondary.jpg',
    ],
    'ERP product media map must preserve the synchronized gallery order.'
);


fwrite(STDOUT, "PASS: catalog image enrichment tests.\n");

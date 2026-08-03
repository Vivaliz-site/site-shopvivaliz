<?php
declare(strict_types=1);

$path = dirname(__DIR__) . '/api/melhorenvio/shipping-check-v2.php';
$source = file_get_contents($path);
if (!is_string($source)) {
    fwrite(STDERR, "FAIL: shipping endpoint could not be read\n");
    exit(1);
}

$forbidden = [
    'PAC (Mock Local)',
    'Sedex (Mock Local)',
    "'provider' => 'melhorenvio_mock'",
    'melhorenvio_mock',
];

foreach ($forbidden as $snippet) {
    if (str_contains($source, $snippet)) {
        fwrite(STDERR, "FAIL: simulated shipping fallback returned: {$snippet}\n");
        exit(1);
    }
}

if (!str_contains($source, "'error'=>'shipping_provider_unavailable'")) {
    fwrite(STDERR, "FAIL: real provider failure path is missing\n");
    exit(1);
}

echo "no-shipping-mock-fallback: ok\n";

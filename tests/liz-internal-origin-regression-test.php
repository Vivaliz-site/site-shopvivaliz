<?php
declare(strict_types=1);

function liz_origin_assert(bool $condition, string $message): void
{
    if ($condition) return;
    fwrite(STDERR, "FAIL: {$message}\n");
    exit(1);
}

$helper = __DIR__ . '/../config/internal-http-origin.php';
liz_origin_assert(is_file($helper), 'internal HTTP origin helper must exist.');
require_once $helper;

putenv('SHOPVIVALIZ_INTERNAL_ORIGIN');
liz_origin_assert(
    shopvivaliz_internal_url('/api/liz-general.php') === 'http://127.0.0.1:8080/api/liz-general.php',
    'Liz must use the private Apache listener on port 8080 after the ARM migration.'
);

putenv('SHOPVIVALIZ_INTERNAL_ORIGIN=http://127.0.0.1:18080');
liz_origin_assert(
    shopvivaliz_internal_url('/api/liz-intelligent.php') === 'http://127.0.0.1:18080/api/liz-intelligent.php',
    'A loopback-only runtime override must support isolated integration tests.'
);

putenv('SHOPVIVALIZ_INTERNAL_ORIGIN=https://example.com');
liz_origin_assert(
    shopvivaliz_internal_url('/api/liz-general.php') === 'http://127.0.0.1:8080/api/liz-general.php',
    'An external origin must never turn Liz into an SSRF proxy.'
);

fwrite(STDOUT, "PASS: Liz internal routing is pinned to a working loopback listener.\n");

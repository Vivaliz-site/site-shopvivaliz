<?php
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/includes/product-historical-aliases.php';

$legacy = 'massa-f12-para-calafetar-madeira-400g-mogno-viapol-411';
$expected = rawurldecode('massa-f12-de-calafetar-e-corre%C3%A7%C3%A3o-madeira-viapol-400g-mogno-v0210691');
$actual = sv_product_historical_alias_target($legacy);

if ($actual !== $expected) {
    fwrite(STDERR, "Historical alias UTF-8 target mismatch\n");
    fwrite(STDERR, 'expected_hex=' . bin2hex($expected) . "\n");
    fwrite(STDERR, 'actual_hex=' . bin2hex((string)$actual) . "\n");
    exit(1);
}

fwrite(STDOUT, "product-historical-alias-utf8-test: ok\n");

<?php
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/includes/product-historical-aliases.php';

$expected = [
    'massa-f12-para-calafetar-madeira-400g-mogno-viapol-411'
        => 'massa-f12-de-calafetar-e-correção-madeira-viapol-400g-mogno-v0210691',
    'casinha-cachorro-52x41x40-astra-pet-azul-504'
        => 'casinha-cachorro-52x41x40-astra-pet-azul-astrapetcasinhacachorroazul',
    'vaso-antique-44-70l-cimento-queimado-japi-546'
        => 'vaso-antique-44-70l-cimento-queimado-japi-jvaqcq44',
];

foreach ($expected as $legacy => $canonical) {
    $actual = sv_product_historical_alias_target($legacy);
    if ($actual !== $canonical) {
        fwrite(STDERR, "Historical alias mismatch: {$legacy} => {$actual}\n");
        exit(1);
    }
}

$unknown = sv_product_historical_alias_target('produto-inexistente-999');
if ($unknown !== null) {
    fwrite(STDERR, "Unknown historical aliases must fail closed\n");
    exit(1);
}

fwrite(STDOUT, "product-historical-aliases-regression-test: ok\n");

$route = file_get_contents($root . '/produto-slug-route.php');
if (!is_string($route)) {
    fwrite(STDERR, "Unable to read product route\n");
    exit(1);
}

$requiredRoute = [
    "require_once __DIR__ . '/includes/product-historical-aliases.php';",
    'sv_product_historical_alias_target($requestedSlug)',
    'sv_product_route_catalog_row_by_slug($provenLegacyTarget)',
    'sv_product_route_redirect($provenLegacySlug)',
];
foreach ($requiredRoute as $needle) {
    if (!str_contains($route, $needle)) {
        fwrite(STDERR, "Historical alias route contract missing: {$needle}\n");
        exit(1);
    }
}

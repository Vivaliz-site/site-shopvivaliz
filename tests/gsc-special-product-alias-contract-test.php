<?php
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/includes/product-historical-aliases.php';
$aliases = [
    'vaso-antique-55-75l-macchiato-japi-brown' => implode('-', ['vaso', 'antique', '55', '75l', 'macchiato', 'japi', 'jvaqma55']),
    'vaso-decor-plantas-cilin-decore-34-28l-aco-corten-japi-168' => rawurldecode('vaso-decore-34-28l-a%C3%A7o-corten-japi-jvcdac34'),
];
foreach ($aliases as $legacy => $canonical) {
    if (sv_product_historical_alias_target($legacy) !== $canonical) {
        fwrite(STDERR, "Historical landing alias {$legacy} must resolve to {$canonical}\n");
        exit(1);
    }
}
$htaccess = (string)file_get_contents($root . '/.htaccess');
foreach (['produto/vaso-antique-55-75l-macchiato-japi-brown/?$ produto.php?id=341533994','produto/vaso-decor-plantas-cilin-decore-34-28l-aco-corten-japi-168/?$ produto.php?id=350509721'] as $needle) {
    if (str_contains($htaccess, $needle)) {
        fwrite(STDERR, "Historical product landing must use canonical route guard, not direct produto.php bypass: {$needle}\n");
        exit(1);
    }
}
fwrite(STDOUT, "gsc-special-product-alias-contract-test: ok\n");

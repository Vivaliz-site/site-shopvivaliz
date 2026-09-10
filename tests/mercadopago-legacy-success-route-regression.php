<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$source = file_get_contents($root . '/includes/mercadopago-checkout-js.php');
if (!is_string($source)) {
    fwrite(STDERR, "FAIL: could not read Mercado Pago checkout include\n");
    exit(1);
}
if (str_contains($source, '/checkout/success')) {
    fwrite(STDERR, "FAIL: legacy Mercado Pago checkout redirects to missing /checkout/success route\n");
    exit(1);
}
if (!str_contains($source, '/checkout/retorno?result=success')) {
    fwrite(STDERR, "FAIL: legacy Mercado Pago checkout does not use canonical checkout return route\n");
    exit(1);
}
echo "mercadopago-legacy-success-route: ok\n";

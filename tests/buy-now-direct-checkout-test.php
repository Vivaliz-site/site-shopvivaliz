<?php
declare(strict_types=1);
$root = dirname(__DIR__);
$product = (string)file_get_contents($root . '/produto.php');
$checkout = (string)file_get_contents($root . '/checkout.php');
function assert_true(bool $ok, string $message): void { if (!$ok) { fwrite(STDERR, "FAIL: $message\n"); exit(1); } }
$start = strpos($product, 'if (buyNowButton)');
$end = strpos($product, '// Product page freight calculation handler', $start === false ? 0 : $start);
assert_true($start !== false && $end !== false, 'buy-now handler not found');
$handler = substr($product, $start, $end - $start);
assert_true(str_contains($handler, "window.location.href='/checkout'"), 'COMPRAR AGORA must go directly to checkout');
assert_true(!str_contains($handler, "window.location.href='/carrinho'"), 'COMPRAR AGORA must not force an intermediate cart page');
assert_true(str_contains($checkout, "localStorage.getItem('shopvivaliz_cart')"), 'checkout must read the saved cart');
assert_true(str_contains($checkout, "/api/melhorenvio/shipping-check-v2.php"), 'checkout must be able to quote shipping directly');
assert_true(str_contains($checkout, '/api/orders/create.php'), 'checkout must create orders through canonical server endpoint');
$create = (string)file_get_contents($root . '/api/orders/create.php');
assert_true(str_contains($create, "require __DIR__ . '/create-validated.php';"), 'canonical create endpoint must delegate to validated order creation');
fwrite(STDOUT, "PASS: buy-now goes directly to self-sufficient checkout.\n");

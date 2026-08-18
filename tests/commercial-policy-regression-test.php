<?php
declare(strict_types=1);

function policy_assert(bool $ok, string $message): void {
    if (!$ok) { fwrite(STDERR, "FAIL: {$message}\n"); exit(1); }
}

$index = file_get_contents(dirname(__DIR__) . '/index.php') ?: '';
$sanitizer = file_get_contents(dirname(__DIR__) . '/includes/product-trust-sanitizer.php') ?: '';

policy_assert(!str_contains($index, 'VIVALIZ10'), 'public home must not advertise legacy VIVALIZ10');
policy_assert(!str_contains($index, 'PRIMEIRA10'), 'public home must not advertise legacy PRIMEIRA10');
policy_assert(!preg_match('/10%[^\n]{0,100}(primeira|1.?)\s*compra/iu', $index), 'public home must not claim 10% first purchase');
policy_assert(str_contains($index, '3% OFF automatico no carrinho'), 'home must advertise the approved automatic 3% cart offer');
policy_assert(str_contains($sanitizer, 'PIX disponível no checkout'), 'PIX claim must stay neutral until checkout-authoritative');
policy_assert(str_contains($sanitizer, 'Parcelamento disponível no checkout'), 'installment claim must stay neutral until checkout-authoritative');

fwrite(STDOUT, "commercial_policy_regression=ok\n");

<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$api = file_get_contents($root . '/api/melhorenvio/shipping-check-v2.php');
$js = file_get_contents($root . '/js/sales-conversion-v1.js');
$css = file_get_contents($root . '/css/sales-conversion-v1.css');
$head = file_get_contents($root . '/includes/head-analytics.php');

if (!is_string($api) || !is_string($js) || !is_string($css) || !is_string($head)) {
    fwrite(STDERR, "shipping-five-options: source read failure\n");
    exit(1);
}

$checks = [
    'API caps quotes at five' => str_contains($api, 'array_slice($options,0,5)')
        && !str_contains($api, 'array_slice($options,0,6)'),
    'Shared shipping layer installed' => str_contains($js, 'installShippingOptions')
        && str_contains($js, 'shipping-check-v2.php'),
    'Product receives up to five' => str_contains($js, "getElementById('p-frete-result')")
        && str_contains($js, 'slice(0,5)'),
    'Direct produto.php loads five-option layer' => str_contains($js, "path==='/produto.php'")
        && str_contains($head, "\$requestPath === '/produto.php'"),
    'Checkout choices persist signed quote' => str_contains($js, 'sv_checkout_shipping_option')
        && str_contains($js, "localStorage.setItem('shopvivaliz_shipping_quote'")
        && str_contains($js, 'quote_id:option.quote_id'),
    'Checkout reopens choices after native render' => str_contains($js, 'observed.hidden')
        && str_contains($js, 'renderPending()'),
    'Every checkout recalculation invalidates stale payment session' => str_contains($js, 'shippingClearPendingPayment();')
        && str_contains($js, "localStorage.removeItem('shopvivaliz_shipping_quote')"),
    'Native checkout cannot persist superseded CEP response' => str_contains($js, 'guardNativeShippingResponse')
        && str_contains($js, 'requestVersion!==latestShippingRequest')
        && str_contains($js, 'AbortController')
        && str_contains($js, 'activeShippingController.abort()'),
    'Checkout CEP and choices freeze during active submission' => str_contains($js, 'svCheckoutSubmitting')
        && str_contains($js, "addEventListener('submit'")
        && str_contains($js, 'cepInput.readOnly=!!active')
        && str_contains($js, 'input.disabled=!!active'),
    'Cart edits invalidate and recalculate shipping choices' => str_contains($js, 'invalidateCheckoutChoices')
        && str_contains($js, ".closest('.qty-btn,.btn-remove')")
        && str_contains($js, 'Itens alterados. Recalculando o frete'),
    'Expired choices force recalculation' => str_contains($js, 'shippingOptionExpired')
        && str_contains($js, 'shippingRecalculateCheckout()'),
    'Selectable cards are styled' => str_contains($css, '.sv-shipping-choice-list')
        && str_contains($css, '.sv-shipping-choice'),
];

$failures = array_keys(array_filter($checks, static fn(bool $ok): bool => !$ok));
if ($failures !== []) {
    fwrite(STDERR, "shipping-five-options: FAIL\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "shipping-five-options: ok\n";

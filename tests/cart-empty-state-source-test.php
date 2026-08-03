<?php
declare(strict_types=1);

$source = file_get_contents(__DIR__ . '/../carrinho.php');
if (!is_string($source)) {
    fwrite(STDERR, "FAIL: carrinho.php could not be read\n");
    exit(1);
}

$forbidden = ['Calculando frete grátis...'];
foreach ($forbidden as $text) {
    if (str_contains($source, $text)) {
        fwrite(STDERR, "FAIL: misleading empty-cart copy remains: {$text}\n");
        exit(1);
    }
}

$required = [
    "btnCheckout.style.pointerEvents = 'none'",
    "btnCheckout.setAttribute('aria-disabled', 'true')",
    "btnCheckout.style.pointerEvents = ''",
    "btnCheckout.removeAttribute('aria-disabled')",
];
foreach ($required as $snippet) {
    if (!str_contains($source, $snippet)) {
        fwrite(STDERR, "FAIL: missing empty-cart checkout protection: {$snippet}\n");
        exit(1);
    }
}

echo "cart-empty-state-source: ok\n";

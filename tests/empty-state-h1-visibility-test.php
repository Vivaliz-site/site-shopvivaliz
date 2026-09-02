<?php
declare(strict_types=1);

$cssPath = dirname(__DIR__) . '/css/visual-polish-v4.css';
$css = file_get_contents($cssPath);
if ($css === false) {
    fwrite(STDERR, "missing visual polish css\n");
    exit(1);
}

$selectors = [
    'html.sv-cart-empty .cart-card > .cart-title',
    'html.sv-checkout-empty .checkout-summary-card > .checkout-title',
];

foreach ($selectors as $selector) {
    $quoted = preg_quote($selector, '~');
    if (preg_match('~' . $quoted . '\s*\{?[^}]*display\s*:\s*none~si', $css) === 1) {
        fwrite(STDERR, "empty-state H1 hidden: {$selector}\n");
        exit(1);
    }
}

echo "empty-state-h1-visibility: ok\n";

<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$cro = (string) file_get_contents($root . '/js/cro-interactions.js');
$privacyCss = (string) file_get_contents($root . '/css/privacy-consent-v1.css');
$visualCss = (string) file_get_contents($root . '/css/visual-audit-v1.css');
$cart = (string) file_get_contents($root . '/carrinho.php');

$checks = [
    'mobile paid landing may reveal sticky CTA before scroll threshold' =>
        str_contains($cro, "window.matchMedia('(max-width: 820px)').matches")
        && str_contains($cro, 'isMobileViewport || window.scrollY > STICKY_REVEAL_SCROLL_Y'),
    'product sticky CTA can move above consent banner' =>
        str_contains($privacyCss, 'body:has(#sv-privacy-consent) .sticky-buy-wrapper.visible')
        && str_contains($privacyCss, 'var(--sv-privacy-consent-space'),
    'cart checkout CTA is fixed on mobile' =>
        preg_match('/@media\s*\(max-width:\s*560px\)[^{]*\{.*?#btn-checkout\s*\{[^}]*position:\s*fixed/s', $visualCss) === 1,
    'cart checkout CTA clears consent banner' =>
        str_contains($privacyCss, 'body:has(#sv-privacy-consent) #btn-checkout')
        && str_contains($privacyCss, 'var(--sv-privacy-consent-space'),
    'cart preserves one canonical validated checkout control' =>
        substr_count($cart, 'id="btn-checkout"') === 1,
];

foreach ($checks as $label => $ok) {
    if (!$ok) {
        fwrite(STDERR, "FAIL: {$label}
");
        exit(1);
    }
}
echo "paid-mobile-cro-visibility: ok
";

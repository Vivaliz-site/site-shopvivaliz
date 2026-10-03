<?php
declare(strict_types=1);

function pmf_assert(bool $ok, string $message): void
{
    if (!$ok) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$root = dirname(__DIR__);
$product = (string)file_get_contents($root . '/produto.php');
$cart = (string)file_get_contents($root . '/carrinho.php');
$cssPath = $root . '/css/paid-mobile-funnel-v1.css';

pmf_assert(is_file($cssPath), 'paid mobile funnel stylesheet must exist');
$css = (string)file_get_contents($cssPath);

pmf_assert(str_contains($product, 'sv-paid-mobile-offer'), 'product page must expose a mobile offer/CTA before the gallery');
pmf_assert(
    strpos($product, 'sv-paid-mobile-offer') < strpos($product, 'product-gallery-column'),
    'mobile offer/CTA must appear before the product gallery in DOM order'
);
pmf_assert(str_contains($product, '$svPrimaryCoupon'), 'mobile offer must use the canonical active coupon source');
pmf_assert(str_contains($product, "document.getElementById('buy-now').click()"), 'mobile offer CTA must delegate to the canonical buy action');
pmf_assert(
    str_contains($product, '/css/paid-mobile-funnel-v1.css?v=') &&
    str_contains($product, "filemtime(__DIR__ . '/css/paid-mobile-funnel-v1.css')"),
    'product page must cache-bust the paid mobile funnel stylesheet'
);

pmf_assert(
    str_contains($cart, '/css/paid-mobile-funnel-v1.css?v=') &&
    str_contains($cart, "filemtime(__DIR__ . '/css/paid-mobile-funnel-v1.css')"),
    'cart must cache-bust the paid mobile funnel stylesheet'
);
pmf_assert(str_contains($css, '#btn-checkout'), 'mobile cart must keep the real checkout CTA visible');
pmf_assert(str_contains($css, 'position: fixed'), 'mobile checkout CTA must be fixed to the viewport');
pmf_assert(str_contains($css, '--sv-privacy-consent-space'), 'mobile checkout CTA must respect the consent banner height');
pmf_assert(str_contains($css, '.sv-paid-mobile-offer'), 'stylesheet must style the product mobile offer');
pmf_assert(str_contains($css, '@media (max-width: 768px)'), 'mobile funnel improvements must be mobile-scoped');

fwrite(STDOUT, "PASS: paid mobile funnel CRO contract.\n");

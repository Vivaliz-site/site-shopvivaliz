<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/public-trust-guard.php';
require_once dirname(__DIR__) . '/includes/checkout-output-hardening.php';
require_once dirname(__DIR__) . '/includes/inventory-reservations.php';

function svh_assert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$root = dirname(__DIR__);
svh_assert(!is_file($root . '/js/home-mobile-layout.js'), 'late DOM reordering script must stay removed');
$clsCss = (string)file_get_contents($root . '/css/cls-stability-v1.css');
svh_assert(str_contains($clsCss, 'overflow-x: clip'), 'root overflow containment must remain enabled');
svh_assert(str_contains($clsCss, '.home-scroller > .home-scroller-arrow'), 'mobile side arrows must stay disabled');
svh_assert(str_contains($clsCss, 'overflow-x: auto !important'), 'internal carousel scrolling must remain available');
$visualPolishJs = (string)file_get_contents($root . '/js/visual-polish-v4.js');
svh_assert(!str_contains($visualPolishJs, 'innerHTML ='), 'visual polish must not rewrite purchase state DOM after paint');
$customCssLoader = (string)file_get_contents($root . '/includes/load-custom-css.php');
svh_assert(str_contains($customCssLoader, 'sv_emit_prepaint_page_state'), 'prepaint page state must be emitted in head');
svh_assert(str_contains($customCssLoader, '/css/cls-stability-v1.css'), 'CLS stability CSS must load last');

$homeJson = json_encode([
    '@context' => 'https://schema.org',
    '@graph' => [
        ['@type' => 'WebSite', 'url' => '/'],
        ['@type' => 'AggregateRating', 'ratingValue' => '4.8', 'ratingCount' => '347'],
        [
            '@type' => 'Product',
            'name' => 'Teste',
            'image' => '/images/logo-vivaliz-square-v2.png',
            'offers' => [
                '@type' => 'Offer',
                'price' => '10.00',
                'priceCurrency' => 'BRL',
                'priceValidUntil' => '2026-12-31',
            ],
        ],
    ],
], JSON_UNESCAPED_SLASHES);

$home = '<html><head><script type="application/ld+json">' . $homeJson . '</script></head><body>'
    . '<!-- Testimonials Section --><section class="home-testimonials"><div class="testimonials-grid"><div>Ana Paula M.</div></div></section>'
    . '<form class="newsletter-form"><input type="email"><button type="submit">Inscrever-se</button></form>'
    . '<script src="/autodev/client.js"></script></body></html>';
$homeFiltered = svptg_sanitize_html($home, 'index.php');
svh_assert(!str_contains($homeFiltered, 'Ana Paula M.'), 'static testimonial must be removed');
svh_assert(str_contains($homeFiltered, 'Carregando avaliações reais'), 'verified testimonial mount must remain');
svh_assert(!str_contains($homeFiltered, '/autodev/client.js'), 'blocked autodev asset must be removed from home');
svh_assert(str_contains($homeFiltered, '/js/newsletter-v1.js'), 'real newsletter client must be injected');
svh_assert(!str_contains($homeFiltered, 'AggregateRating'), 'unverified aggregate rating must be removed');
svh_assert(!str_contains($homeFiltered, 'priceValidUntil'), 'invented price validity must be removed');
svh_assert(str_contains($homeFiltered, 'https://shopvivaliz.com.br/images/product-placeholder.svg'), 'structured image must be absolute and neutral');

$catalog = '<html><body><main>Catálogo</main><script src="/autodev/client.js?debug=1"></script></body></html>';
$catalogFiltered = svptg_sanitize_html($catalog, 'catalogo.php');
svh_assert(!str_contains($catalogFiltered, '/autodev/client.js'), 'blocked autodev asset must be removed from catalog');
svh_assert(str_contains($catalogFiltered, 'Catálogo'), 'catalog content must remain');

$productJson = json_encode([
    '@context' => 'https://schema.org',
    '@type' => 'FAQPage',
    'mainEntity' => [],
], JSON_UNESCAPED_SLASHES);
$product = '<html><head><script type="application/ld+json">' . $productJson . '</script></head><body>'
    . '<div style="color: #fbbf24; font-size: 14px;">★★★★★ <span>(4.9/5 - Excelente)</span></div>'
    . '<span>Garantia de Fábrica</span>'
    . '<!-- Customer Reviews Widget --><section class="container sv-reviews-section"><p>Carlos M.</p></section>'
    . '<script src="/autodev/client.js"></script>'
    . '</body></html>';
$productFiltered = svptg_sanitize_html($product, 'produto.php');
svh_assert(!str_contains($productFiltered, '4.9/5'), 'hard-coded product rating must be removed');
svh_assert(!str_contains($productFiltered, 'Carlos M.'), 'hard-coded review widget must be removed');
svh_assert(!str_contains($productFiltered, '/autodev/client.js'), 'blocked autodev asset must be removed from product');
svh_assert(str_contains($productFiltered, 'quando aplicável'), 'guarantee copy must be qualified');
svh_assert(!str_contains($productFiltered, 'FAQPage'), 'invisible product FAQ schema must be removed');

$checkout = '<html><head>'
    . '<script src="https://sdk.mercadopago.com/js/v2"></script>'
    . '<script src="https://www.mercadopago.com/v2/security.js" output="deviceId"></script>'
    . '</head><body>'
    . 'Garanta o seu estoque! Os produtos no seu carrinho estão reservados por <strong id="checkout-timer-display">15:00</strong> minutos.'
    . '<script>var shippingQuote = getShippingQuote(); var shippingTotal = shippingQuote && Number(shippingQuote.total || 0) > 0 ? Number(shippingQuote.total || 0) : 0; var total = items.reduce(function(a,i){ return a+(parseFloat(i.price)||0)*(i.quantity||1); }, 0) + shippingTotal; var totalFmt = fmtMoney(total);</script>'
    . '</body></html>';
$checkoutFiltered = svcoh_filter($checkout);
svh_assert(str_contains($checkoutFiltered, '<script defer src="https://sdk.mercadopago.com/js/v2"></script>'), 'Mercado Pago SDK must be deferred');
svh_assert(!str_contains($checkoutFiltered, 'estão reservados por'), 'fake pre-submit reservation must be removed');
svh_assert(str_contains($checkoutFiltered, 'Number(order.total)'), 'checkout must use authoritative total');
svh_assert(str_contains($checkoutFiltered, '/js/checkout-resilience-v1.js'), 'checkout resilience client must be injected');

svh_assert(svir_ttl_minutes('pix') === 30, 'PIX reservation TTL');
svh_assert(svir_ttl_minutes('mercado_pago') === 120, 'hosted checkout reservation TTL');
svh_assert(svir_ttl_minutes('boleto') === 4320, 'boleto reservation TTL');

echo "storefront-hardening: ok\n";

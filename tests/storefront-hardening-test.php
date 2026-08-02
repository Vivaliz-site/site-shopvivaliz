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
svh_assert(!str_contains($homeFiltered, '/autodev/client.js'), 'blocked autodev asset must be removed');
svh_assert(str_contains($homeFiltered, '/js/newsletter-v1.js'), 'real newsletter client must be injected');
svh_assert(!str_contains($homeFiltered, 'AggregateRating'), 'unverified aggregate rating must be removed');
svh_assert(!str_contains($homeFiltered, 'priceValidUntil'), 'invented price validity must be removed');
svh_assert(str_contains($homeFiltered, 'https://shopvivaliz.com.br/images/product-placeholder.svg'), 'structured image must be absolute and neutral');

$productJson = json_encode([
    '@context' => 'https://schema.org',
    '@type' => 'FAQPage',
    'mainEntity' => [],
], JSON_UNESCAPED_SLASHES);
$product = '<html><head><script type="application/ld+json">' . $productJson . '</script></head><body>'
    . '<div style="color: #fbbf24; font-size: 14px;">★★★★★ <span>(4.9/5 - Excelente)</span></div>'
    . '<span>Garantia de Fábrica</span>'
    . '<!-- Customer Reviews Widget --><section class="container sv-reviews-section"><p>Carlos M.</p></section>'
    . '</body></html>';
$productFiltered = svptg_sanitize_html($product, 'produto.php');
svh_assert(!str_contains($productFiltered, '4.9/5'), 'hard-coded product rating must be removed');
svh_assert(!str_contains($productFiltered, 'Carlos M.'), 'hard-coded review widget must be removed');
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

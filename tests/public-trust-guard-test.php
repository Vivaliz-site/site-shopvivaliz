<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/public-trust-guard.php';

function assert_true(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$home = <<<'HTML'
<script type="application/ld+json">
{"@graph":[{"@type":"ItemList"},{"@type":"AggregateRating","ratingValue":"4.8","ratingCount":"347","bestRating":"5","worstRating":"1"},{"@type":"BreadcrumbList"}]}
</script>
<!-- Testimonials Section -->
<section class="home-testimonials home-section-shell"><div class="testimonials-grid"><article>Ana Paula M.</article></div></section>
<section class="home-newsletter">Newsletter</section>
HTML;

$homeSanitized = svptg_sanitize_html($home, 'index.php');
assert_true(!str_contains($homeSanitized, 'AggregateRating'), 'home aggregate rating must be removed');
assert_true(!str_contains($homeSanitized, 'Ana Paula M.'), 'home static testimonial must be removed');
assert_true(str_contains($homeSanitized, 'home-testimonials'), 'verified testimonial mount must be preserved');
assert_true(str_contains($homeSanitized, 'testimonials-grid'), 'verified testimonial grid must be preserved');
assert_true(str_contains($homeSanitized, 'BreadcrumbList'), 'home breadcrumb JSON-LD must be preserved');
assert_true(str_contains($homeSanitized, 'home-newsletter'), 'unrelated home content must be preserved');

$product = <<<'HTML'
<h1>Produto</h1>
<div style="color: #fbbf24; font-size: 14px; margin-bottom: 10px;">★★★★★ <span style="color: #6b7280; font-size: 12px; margin-left: 5px;">(4.9/5 - Excelente)</span></div>
<p>Descrição original: Garantia de Fábrica conforme embalagem.</p>
<span>Garantia de Fábrica</span>
<!-- Customer Reviews Widget -->
<section class="container sv-reviews-section">
    <strong>4.9 / 5.0</strong>
    <span>(Baseado em compras verificadas)</span>
    <div>Carlos M. - Divinópolis/MG</div>
    <div>Fernanda S. - São Paulo/SP</div>
</section>
<button id="buy-now">Comprar</button>
HTML;

$productSanitized = svptg_sanitize_html($product, 'produto.php');
assert_true(!str_contains($productSanitized, '4.9/5'), 'product hard-coded rating must be removed');
assert_true(!str_contains($productSanitized, '4.9 / 5.0'), 'product formatted hard-coded rating must be removed');
assert_true(!str_contains($productSanitized, 'Baseado em compras verificadas'), 'unverified purchase claim must be removed');
assert_true(!str_contains($productSanitized, 'Carlos M.'), 'first static reviewer name must be removed');
assert_true(!str_contains($productSanitized, 'Fernanda S.'), 'second static reviewer name must be removed');
assert_true(!str_contains($productSanitized, 'sv-reviews-section'), 'product static reviews must be removed');
assert_true(str_contains($productSanitized, '<span>Garantia legal e do fabricante, quando aplicável</span>'), 'qualified guarantee badge must be present');
assert_true(str_contains($productSanitized, 'Descrição original: Garantia de Fábrica conforme embalagem.'), 'product description must remain intact');
assert_true(str_contains($productSanitized, 'buy-now'), 'product CTA must be preserved');

$original = '<main>preserve me</main>';
assert_true(
    svptg_replace_or_original($original, '~[invalid~', '', 1) === $original,
    'regex failure must preserve the original HTML'
);

echo "public-trust-guard: ok\n";

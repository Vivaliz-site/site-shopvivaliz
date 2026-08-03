<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/product-trust-sanitizer.php';

function svpts_assert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$input = <<<'HTML'
<main>
<h1>Produto real</h1>
<div style="color: #fbbf24; font-size: 14px; margin-bottom: 10px;">★★★★★ <span>(4.9/5 - Excelente)</span></div>
<!-- Customer Reviews Widget -->
<section class="container sv-reviews-section" style="padding:24px">
  <div><strong>4.9 / 5.0</strong><span>(Baseado em compras verificadas)</span></div>
  <div><strong>Carlos M. - Divinópolis/MG</strong></div>
  <div><strong>Fernanda S. - São Paulo/SP</strong></div>
</section>
<section class="container sv-compre-junto" style="padding:24px">
  <h2>Compre Junto e Economize (Combo Recomendado)</h2>
  <a>Adicionar Combo</a>
</section>
<section id="real-product-content">Descrição real preservada.</section>
</main>
HTML;

$output = svpts_sanitize_product_html($input);

foreach ([
    'Carlos M. - Divinópolis/MG',
    'Fernanda S. - São Paulo/SP',
    '4.9 / 5.0',
    '4.9/5 - Excelente',
    'Baseado em compras verificadas',
    'Compre Junto e Economize',
    'Adicionar Combo',
] as $forbidden) {
    svpts_assert(!str_contains($output, $forbidden), 'forbidden claim remains: ' . $forbidden);
}

svpts_assert(str_contains($output, 'Ainda não há avaliações publicadas para este produto.'), 'honest empty-state must be present');
svpts_assert(str_contains($output, '/avaliacoes.php'), 'real review submission link must be present');
svpts_assert(str_contains($output, 'Descrição real preservada.'), 'unrelated product content must remain');
svpts_assert(substr_count($output, 'sv-reviews-section') === 1, 'exactly one honest review section must remain');

echo "product_trust_sanitizer_test=passed\n";

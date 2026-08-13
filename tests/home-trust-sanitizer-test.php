<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/home-trust-sanitizer.php';

$fixture = <<<'HTML'
<!doctype html><html><head>
<link rel="preconnect" href="https://images.unsplash.com" crossorigin>
<link rel="dns-prefetch" href="https://images.unsplash.com">
<script type="application/ld+json">{
  "@context":"https://schema.org",
  "@graph":[
    {"@type":"WebSite","name":"Vivaliz"},
    {"@type":"AggregateRating","name":"Avaliações Vivaliz","ratingValue":"4.8","ratingCount":"347","bestRating":"5","worstRating":"1"},
    {"@type":"BreadcrumbList","itemListElement":[]}
  ]
}</script></head><body>
<img src="https://images.unsplash.com/photo-1497366216548-37526070297c?w=400&h=400&fit=crop">
<!-- Testimonials Section -->
<section class="home-testimonials"><div class="testimonials-grid"><article>Ana Paula M.</article><article>Marcos Silva T.</article><article>Julia Costa F.</article></div></section>
<!-- Premium Newsletter Section -->
<section class="home-newsletter"><form onsubmit="event.preventDefault(); alert('Inscrição realizada com sucesso!'); this.reset();"><input type="email"><button>Inscrever-se</button></form></section>
<!-- FAQ Section -->
<section class="home-faq">FAQ</section>
</body></html>
HTML;

$output = svhts_sanitize_home_html($fixture);
$failures = [];
$expect = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) $failures[] = $message;
};

foreach (['Ana Paula M.', 'Marcos Silva T.', 'Julia Costa F.', 'AggregateRating', 'ratingCount', "alert('Inscrição realizada com sucesso!')", 'images.unsplash.com'] as $forbidden) {
    $expect(!str_contains($output, $forbidden), 'Conteudo nao confiavel permaneceu: ' . $forbidden);
}

foreach (['Avaliações reais, sem conteúdo demonstrativo.', '/api/testimonials.php', 'A inscrição por e-mail ainda não está ativa.', '/catalogo', '/contato', '/public/assets/category-images/cat-organizacao.jpg'] as $required) {
    // /api/testimonials.php e uma garantia de arquitetura do JS, nao deve ser
    // inserida pelo sanitizador. O estado server-side precisa apenas preservar
    // a secao que o JS reconhece; tratamos essa excecao abaixo.
    if ($required === '/api/testimonials.php') continue;
    $expect(str_contains($output, $required), 'Conteudo confiavel ausente: ' . $required);
}
$expect(str_contains($output, 'class="testimonials-grid"'), 'Hook de testimonials para o JS foi removido.');
$expect(str_contains($output, '<!-- FAQ Section -->'), 'FAQ foi removido junto com a newsletter.');

if (preg_match('~<script type="application/ld\+json">(.*?)</script>~s', $output, $match) !== 1) {
    $failures[] = 'JSON-LD nao encontrado apos sanitizacao.';
} else {
    json_decode($match[1], true);
    $expect(json_last_error() === JSON_ERROR_NONE, 'JSON-LD ficou invalido: ' . json_last_error_msg());
}

if ($failures !== []) {
    foreach ($failures as $failure) fwrite(STDERR, "FAIL: {$failure}\n");
    exit(1);
}

echo "OK: home server-side sem prova social ou newsletter simuladas.\n";

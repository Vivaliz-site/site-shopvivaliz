<?php

declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/includes/home-trust-sanitizer.php';

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

$failures = [];
$expect = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) $failures[] = $message;
};

$assertSanitized = static function (string $output, string $context) use ($expect): void {
    foreach (['Ana Paula M.', 'Marcos Silva T.', 'Julia Costa F.', 'AggregateRating', 'ratingCount', 'Inscrição realizada com sucesso!', 'images.unsplash.com'] as $forbidden) {
        $expect(!str_contains($output, $forbidden), $context . ': conteudo nao confiavel permaneceu: ' . $forbidden);
    }
    foreach (['Avaliações reais, sem conteúdo demonstrativo.', 'A inscrição por e-mail ainda não está ativa.', '/catalogo', '/contato', '/public/assets/category-images/cat-organizacao.jpg'] as $required) {
        $expect(str_contains($output, $required), $context . ': conteudo confiavel ausente: ' . $required);
    }
    $expect(str_contains($output, 'class="testimonials-grid"'), $context . ': hook de testimonials para o JS foi removido.');
    $expect(str_contains($output, '<!-- FAQ Section -->'), $context . ': FAQ foi removido junto com a newsletter.');
};

$expect(svhts_is_home_path('/'), 'A rota raiz deve ser tratada como home.');
$expect(svhts_is_home_path('/index.php'), 'A rota direta /index.php deve receber as mesmas protecoes da home.');
$expect(!svhts_is_home_path('/catalogo'), 'O sanitizador nao pode vazar para outras rotas.');

$output = svhts_sanitize_home_html($fixture);
$assertSanitized($output, 'fixture');

if (preg_match('~<script type="application/ld\+json">(.*?)</script>~s', $output, $match) !== 1) {
    $failures[] = 'Fixture: JSON-LD nao encontrado apos sanitizacao.';
} else {
    json_decode($match[1], true);
    $expect(json_last_error() === JSON_ERROR_NONE, 'Fixture: JSON-LD ficou invalido: ' . json_last_error_msg());
}

// Garante que os regexes continuam alcançando o index.php real. Nao executamos
// a home nem acessamos banco/secrets; tratamos o arquivo como texto e validamos
// exatamente o HTML/PHP versionado que sera renderizado em producao.
$indexSource = @file_get_contents($root . '/index.php');
if (!is_string($indexSource) || $indexSource === '') {
    $failures[] = 'Nao foi possivel ler index.php para regressao real.';
} else {
    $expect(str_contains($indexSource, 'AggregateRating'), 'Index real deixou de conter o marcador esperado; revisar/remover o filtro legado.');
    $expect(str_contains($indexSource, 'Ana Paula M.'), 'Index real deixou de conter o depoimento legado esperado; revisar/remover o filtro legado.');
    $expect(str_contains($indexSource, "alert('Inscrição realizada com sucesso!"), 'Index real deixou de conter a newsletter legada esperada; revisar/remover o filtro legado.');
    $sanitizedIndex = svhts_sanitize_home_html($indexSource);
    $assertSanitized($sanitizedIndex, 'index.php');
}

if ($failures !== []) {
    foreach ($failures as $failure) fwrite(STDERR, "FAIL: {$failure}\n");
    exit(1);
}

echo "OK: home server-side sem prova social ou newsletter simuladas.\n";

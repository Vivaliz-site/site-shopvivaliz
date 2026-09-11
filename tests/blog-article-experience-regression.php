<?php
declare(strict_types=1);

$path = __DIR__ . '/../blog/artigo.php';
$source = (string)file_get_contents($path);

$required = [
    'article-toc',
    'secao-',
    'Ver opções no catálogo',
    'article-comments',
    'article-faq',
];
foreach ($required as $needle) {
    if (!str_contains($source, $needle)) {
        fwrite(STDERR, "Experiencia do artigo ausente: {$needle}\n");
        exit(1);
    }
}

if (!str_contains($source, 'related_products_url')) {
    fwrite(STDERR, "CTA contextual precisa preservar a URL de catalogo do artigo.\n");
    exit(1);
}

fwrite(STDOUT, "OK blog article experience regression\n");

<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/blog-article-repository.php';

$repository = new BlogArticleRepository(null);
$articles = $repository->published('', '', 200);
if ($articles === []) {
    fwrite(STDERR, "Nenhum artigo disponível para validar imagens.\n");
    exit(1);
}

$root = dirname(__DIR__);
foreach ($articles as $article) {
    $image = trim((string)($article['image'] ?? ''));
    if ($image === '') {
        fwrite(STDERR, 'Imagem vazia em ' . (string)($article['slug'] ?? 'sem-slug') . "\n");
        exit(1);
    }
    if (str_starts_with($image, 'http://') || str_starts_with($image, 'https://') || str_starts_with($image, '//')) {
        continue;
    }
    $path = (string)(parse_url($image, PHP_URL_PATH) ?: $image);
    if (!is_file($root . '/' . ltrim($path, '/'))) {
        fwrite(STDERR, 'Imagem local inexistente em ' . (string)($article['slug'] ?? 'sem-slug') . ': ' . $image . "\n");
        exit(1);
    }
}

$autopilot = sv_blog_editorial_build_article('Como evitar que parafusos e buchas fiquem soltos', 'wednesday');
$reflection = new ReflectionClass(BlogArticleRepository::class);
$resolver = $reflection->getMethod('resolveImage');
$resolver->setAccessible(true);
$resolved = (string)$resolver->invoke($repository, (string)$autopilot['image_url'], $autopilot);
if (!is_file($root . '/' . ltrim($resolved, '/'))) {
    fwrite(STDERR, 'Autopilot não recebeu fallback de imagem válido: ' . $resolved . "\n");
    exit(1);
}

fwrite(STDOUT, "OK blog image fallback smoke\n");

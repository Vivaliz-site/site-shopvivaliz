<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/blog/content.php';
$articles = sv_blog_articles();
if (count($articles) < 10) {
    fwrite(STDERR, "Unexpectedly small editorial inventory\n");
    exit(1);
}
$checked = 0;
foreach ($articles as $article) {
    $path = (string) ($article['image'] ?? '');
    if (!str_starts_with($path, '/public/assets/blog/category-editorial/')) {
        fwrite(STDERR, "Missing approved editorial image for {$article['slug']}: {$path}\n");
        exit(1);
    }
    $absolute = dirname(__DIR__) . $path;
    $size = @getimagesize($absolute);
    if ($size === false || $size[0] < 720 || $size[1] < 405) {
        fwrite(STDERR, "Unusable image: {$absolute}\n");
        exit(1);
    }
    $checked++;
}
echo "PASS: {$checked} editorial articles have real local images (720x405 minimum)\n";

require_once dirname(__DIR__) . '/includes/blog-article-repository.php';
$repository = new BlogArticleRepository(null);
$published = $repository->published('', '', 100);
if (count($published) !== $checked) {
    fwrite(STDERR, "Published article inventory mismatch\n");
    exit(1);
}
foreach ($published as $article) {
    if (!str_starts_with((string)$article['image'], '/public/assets/blog/category-editorial/')) {
        fwrite(STDERR, "Published article still has placeholder: {$article['slug']}\n");
        exit(1);
    }
}
$method = new ReflectionMethod(BlogArticleRepository::class, 'resolveImage');
foreach (['ferramentas', 'ferragens', 'organizacao', 'rodizios', 'jardim'] as $category) {
    $legacy = '/public/assets/category-images/cat-' . $category . '.jpg';
    $resolved = $method->invoke($repository, $legacy, ['slug' => 'sample', 'title' => $category]);
    if ($resolved !== '/public/assets/blog/category-editorial/' . $category . ($category === 'ferramentas' ? '-v2' : '') . '.jpg') {
        fwrite(STDERR, "Legacy fallback was not repaired: {$legacy}\n");
        exit(1);
    }
}
$svg = $method->invoke($repository, '/public/assets/blog/como-escolher-ferramentas-para-casa.svg', [
    'slug' => 'como-escolher-ferramentas-para-casa',
    'title' => 'Como escolher ferramentas',
    'category' => 'Ferramentas',
]);
if ($svg !== '/public/assets/blog/category-editorial/ferramentas-v2.jpg') {
    fwrite(STDERR, "Legacy SVG override not repaired\n");
    exit(1);
}
echo "PASS: normalized published images, five legacy categories and generated SVG fallback\n";

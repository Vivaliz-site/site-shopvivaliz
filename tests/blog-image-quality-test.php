<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/blog-article-repository.php';
$repo = BlogArticleRepository::fromApplicationDatabase();
$articles = $repo->published('', '', 200);
$errors = [];
$seen = [];
$root = dirname(__DIR__);
foreach ($articles as $article) {
    $slug = (string)($article['slug'] ?? '');
    $image = trim((string)($article['image'] ?? ''));
    if ($slug === '') {
        $errors[] = 'article_without_slug';
        continue;
    }
    if ($image === '') {
        $errors[] = $slug . ':missing_image';
        continue;
    }
    if (!preg_match('#^https?://#i', $image)) {
        $path = (string)(parse_url($image, PHP_URL_PATH) ?: $image);
        if (!is_file($root . '/' . ltrim($path, '/'))) {
            $errors[] = $slug . ':image_file_missing:' . $image;
        }
    }
    $key = strtolower((string)(parse_url($image, PHP_URL_PATH) ?: $image));
    if (isset($seen[$key])) {
        $errors[] = $slug . ':duplicate_image_with:' . $seen[$key] . ':' . $image;
    } else {
        $seen[$key] = $slug;
    }
}
if ($errors !== []) {
    fwrite(STDERR, "BLOG_IMAGE_QUALITY_FAILED\n" . implode("\n", $errors) . "\n");
    exit(1);
}
echo 'BLOG_IMAGE_QUALITY_OK articles=' . count($articles) . PHP_EOL;

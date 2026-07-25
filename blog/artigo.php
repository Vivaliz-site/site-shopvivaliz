<?php
declare(strict_types=1);

require_once __DIR__ . '/content.php';
require_once __DIR__ . '/../includes/blog-article-repository.php';

$slug = trim((string)($_GET['slug'] ?? ''));
$article = BlogArticleRepository::fromApplicationDatabase()->findPublishedBySlug($slug);

if ($article === null) {
    http_response_code(404);
    require __DIR__ . '/../404.php';
    exit;
}

$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'] ?? 'shopvivaliz.com.br';
$canonical = $scheme . '://' . $host . '/blog/' . rawurlencode((string)$article['slug']);
$schema = [
    '@context' => 'https://schema.org',
    '@type' => 'Article',
    'headline' => $article['title'],
    'description' => $article['meta_description'],
    'image' => $scheme . '://' . $host . $article['image'],
    'datePublished' => $article['published_at'],
    'dateModified' => $article['updated_at'],
    'author' => ['@type' => 'Organization', 'name' => $article['author']],
    'publisher' => ['@type' => 'Organization', 'name' => 'ShopVivaliz'],
    'mainEntityOfPage' => $canonical,
];

$faqSchema = [
    '@context' => 'https://schema.org',
    '@type' => 'FAQPage',
    'mainEntity' => array_map(static fn(array $item): array => [
        '@type' => 'Question',
        'name' => $item['question'],
        'acceptedAnswer' => ['@type' => 'Answer', 'text' => $item['answer']],
    ], $article['faq']),
];
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= sv_blog_escape((string)$article['meta_title']) ?></title>
    <meta name="description" content="<?= sv_blog_escape((string)$article['meta_description']) ?>">
    <meta name="keywords" content="<?= sv_blog_escape(implode(', ', $article['keywords'])) ?>">
    <link rel="canonical" href="<?= sv_blog_escape($canonical) ?>">
    <meta property="og:type" content="article">
    <meta property="og:title" content="<?= sv_blog_escape((string)$article['title']) ?>">
    <meta property="og:description" content="<?= sv_blog_escape((string)$article['meta_description']) ?>">
    <meta property="og:url" content="<?= sv_blog_escape($canonical) ?>">
    <meta property="og:image" content="<?= sv_blog_escape($scheme . '://' . $host . $article['image']) ?>">
    <meta property="og:locale" content="pt_BR">
    <meta name="twitter:card" content="summary_large_image">
    <link rel="stylesheet" href="/css/responsive.css">
    <link rel="stylesheet" href="/public/assets/blog/blog.css">
    <script type="application/ld+json"><?= json_encode($schema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?></script>
    <script type="application/ld+json"><?= json_encode($faqSchema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?></script>
</head>
<body>
<?php $svNavCurrent = 'blog'; include __DIR__ . '/../includes/navbar.php'; ?>
<main class="article-shell">
    <nav class="article-breadcrumb" aria-label="Navegação estrutural">
        <a href="/">Início</a> / <a href="/blog">Central de Conhecimento</a> / <?= sv_blog_escape((string)$article['category']) ?>
    </nav>

    <article>
        <header class="article-header">
            <span class="knowledge-chip"><?= sv_blog_escape((string)$article['category']) ?></span>
            <h1><?= sv_blog_escape((string)$article['title']) ?></h1>
            <p class="article-lead"><?= sv_blog_escape((string)$article['excerpt']) ?></p>
            <div class="knowledge-meta">
                <span>Por <?= sv_blog_escape((string)$article['author']) ?></span>
                <span>Publicado em <?= sv_blog_escape(sv_blog_date((string)$article['published_at'])) ?></span>
                <span><?= (int)$article['reading_time'] ?> min de leitura</span>
            </div>
        </header>

        <img class="article-cover" src="<?= sv_blog_escape((string)$article['image']) ?>" alt="<?= sv_blog_escape((string)$article['image_alt']) ?>" loading="eager">

        <div class="article-content">
            <?php foreach ($article['content'] as $section): ?>
                <section>
                    <h2><?= sv_blog_escape((string)$section['heading']) ?></h2>
                    <?php foreach (($section['paragraphs'] ?? []) as $paragraph): ?>
                        <p><?= sv_blog_escape((string)$paragraph) ?></p>
                    <?php endforeach; ?>
                    <?php if (!empty($section['list'])): ?>
                        <ul>
                            <?php foreach ($section['list'] as $item): ?>
                                <li><?= sv_blog_escape((string)$item) ?></li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </section>
            <?php endforeach; ?>
        </div>

        <aside class="article-cta">
            <h2>Encontre produtos para o seu projeto</h2>
            <p>Explore o catálogo da ShopVivaliz e compare as opções disponíveis para a sua necessidade.</p>
            <a href="<?= sv_blog_escape((string)$article['related_products_url']) ?>">Ver produtos relacionados</a>
        </aside>

        <section class="article-faq" aria-labelledby="faq-title">
            <h2 id="faq-title">Perguntas frequentes</h2>
            <?php foreach ($article['faq'] as $faq): ?>
                <details>
                    <summary><?= sv_blog_escape((string)$faq['question']) ?></summary>
                    <p><?= sv_blog_escape((string)$faq['answer']) ?></p>
                </details>
            <?php endforeach; ?>
        </section>
    </article>
</main>
<?php include __DIR__ . '/../includes/footer.php'; ?>
</body>
</html>

<?php
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/content.php';
require_once __DIR__ . '/../includes/blog-article-repository.php';

$slug = trim((string)($_GET['slug'] ?? ''));
$repository = BlogArticleRepository::fromApplicationDatabase();
$article = $repository->findPublishedBySlug($slug);

if ($article === null) {
    http_response_code(404);
    require __DIR__ . '/../404.php';
    exit;
}

$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'] ?? 'shopvivaliz.com.br';
$origin = $scheme . '://' . $host;
$canonical = $origin . '/blog/' . rawurlencode((string)$article['slug']);
$image = trim((string)($article['image'] ?? ''));
$imageAbsolute = $image !== '' && str_starts_with($image, 'http') ? $image : $origin . '/' . ltrim($image, '/');

$schema = [
    '@context' => 'https://schema.org',
    '@type' => 'Article',
    'headline' => $article['title'],
    'description' => $article['meta_description'],
    'image' => $imageAbsolute,
    'datePublished' => $article['published_at'],
    'dateModified' => $article['updated_at'],
    'author' => ['@type' => 'Organization', 'name' => $article['author']],
    'publisher' => ['@type' => 'Organization', 'name' => 'ShopVivaliz', 'url' => $origin],
    'mainEntityOfPage' => ['@type' => 'WebPage', '@id' => $canonical],
];

$breadcrumbSchema = [
    '@context' => 'https://schema.org',
    '@type' => 'BreadcrumbList',
    'itemListElement' => [
        ['@type' => 'ListItem', 'position' => 1, 'name' => 'Início', 'item' => $origin . '/'],
        ['@type' => 'ListItem', 'position' => 2, 'name' => 'Central de Conhecimento', 'item' => $origin . '/blog'],
        ['@type' => 'ListItem', 'position' => 3, 'name' => (string)$article['title'], 'item' => $canonical],
    ],
];

$faqItems = array_values(array_filter((array)($article['faq'] ?? []), static fn($item): bool =>
    is_array($item) && trim((string)($item['question'] ?? '')) !== '' && trim((string)($item['answer'] ?? '')) !== ''
));
$faqSchema = $faqItems === [] ? null : [
    '@context' => 'https://schema.org',
    '@type' => 'FAQPage',
    'mainEntity' => array_map(static fn(array $item): array => [
        '@type' => 'Question',
        'name' => (string)$item['question'],
        'acceptedAnswer' => ['@type' => 'Answer', 'text' => (string)$item['answer']],
    ], $faqItems),
];

$related = [];
foreach ($repository->published('', (string)($article['category'] ?? ''), 8) as $candidate) {
    if (($candidate['slug'] ?? '') === $article['slug']) continue;
    $related[] = $candidate;
    if (count($related) >= 3) break;
}
if (count($related) < 3) {
    foreach ($repository->published('', '', 12) as $candidate) {
        if (($candidate['slug'] ?? '') === $article['slug']) continue;
        foreach ($related as $existing) {
            if (($existing['slug'] ?? '') === ($candidate['slug'] ?? '')) continue 2;
        }
        $related[] = $candidate;
        if (count($related) >= 3) break;
    }
}
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
    <link rel="alternate" type="application/rss+xml" title="Central de Conhecimento ShopVivaliz" href="/blog/feed.xml">
    <meta property="og:type" content="article">
    <meta property="og:title" content="<?= sv_blog_escape((string)$article['title']) ?>">
    <meta property="og:description" content="<?= sv_blog_escape((string)$article['meta_description']) ?>">
    <meta property="og:url" content="<?= sv_blog_escape($canonical) ?>">
    <meta property="og:image" content="<?= sv_blog_escape($imageAbsolute) ?>">
    <meta property="og:locale" content="pt_BR">
    <meta name="twitter:card" content="summary_large_image">
    <link rel="stylesheet" href="/css/responsive.css">
    <link rel="stylesheet" href="/public/assets/blog/blog.css">
    <script type="application/ld+json"><?= json_encode($schema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?></script>
    <script type="application/ld+json"><?= json_encode($breadcrumbSchema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?></script>
    <?php if ($faqSchema !== null): ?><script type="application/ld+json"><?= json_encode($faqSchema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?></script><?php endif; ?>
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
            <div class="knowledge-meta"><span>Por <?= sv_blog_escape((string)$article['author']) ?></span><span>Publicado em <?= sv_blog_escape(sv_blog_date((string)$article['published_at'])) ?></span><span><?= (int)$article['reading_time'] ?> min de leitura</span></div>
        </header>
        <?php if ($image !== ''): ?><img class="article-cover" src="<?= sv_blog_escape($image) ?>" alt="<?= sv_blog_escape((string)$article['image_alt']) ?>" loading="eager"><?php endif; ?>
        <div class="article-content">
            <?php foreach ($article['content'] as $section): ?>
                <section><h2><?= sv_blog_escape((string)($section['heading'] ?? '')) ?></h2>
                    <?php foreach (($section['paragraphs'] ?? []) as $paragraph): ?><p><?= sv_blog_escape((string)$paragraph) ?></p><?php endforeach; ?>
                    <?php if (!empty($section['list'])): ?><ul><?php foreach ($section['list'] as $item): ?><li><?= sv_blog_escape((string)$item) ?></li><?php endforeach; ?></ul><?php endif; ?>
                </section>
            <?php endforeach; ?>
        </div>
        <aside class="article-cta"><h2>Encontre produtos para o seu projeto</h2><p>Explore o catálogo da ShopVivaliz e compare as opções disponíveis para a sua necessidade.</p><a href="<?= sv_blog_escape((string)$article['related_products_url']) ?>">Ver produtos relacionados</a></aside>
        <section class="article-comments" aria-labelledby="comments-title">
            <div class="article-comments-head">
                <h2 id="comments-title">Perguntas e comentários</h2>
                <p>Ficou com dúvida sobre este conteúdo ou quer pedir recomendação de produto? Envie sua mensagem e seguimos pelo canal mais rápido.</p>
            </div>
            <form class="article-comments-form" action="https://formsubmit.co/shopvivaliz@gmail.com" method="post">
                <input type="hidden" name="_subject" value="Pergunta do blog ShopVivaliz">
                <input type="hidden" name="_captcha" value="false">
                <input type="hidden" name="_template" value="table">
                <input type="hidden" name="artigo" value="<?= sv_blog_escape((string)$article['title']) ?>">
                <div class="article-comments-grid">
                    <label>
                        <span>Seu nome</span>
                        <input type="text" name="nome" autocomplete="name" placeholder="Como podemos te chamar?" required>
                    </label>
                    <label>
                        <span>Seu e-mail</span>
                        <input type="email" name="email" autocomplete="email" placeholder="voce@exemplo.com" required>
                    </label>
                </div>
                <label>
                    <span>Sua pergunta ou comentário</span>
                    <textarea name="mensagem" rows="5" placeholder="Escreva sua dúvida, comentário ou o que você quer encontrar no site." required></textarea>
                </label>
                <div class="article-comments-actions">
                    <button type="submit">Enviar mensagem</button>
                    <a href="/contato">Abrir atendimento completo</a>
                </div>
            </form>
        </section>
        <?php if ($faqItems !== []): ?><section class="article-faq" aria-labelledby="faq-title"><h2 id="faq-title">Perguntas frequentes</h2><?php foreach ($faqItems as $faq): ?><details><summary><?= sv_blog_escape((string)$faq['question']) ?></summary><p><?= sv_blog_escape((string)$faq['answer']) ?></p></details><?php endforeach; ?></section><?php endif; ?>
        <?php if ($related !== []): ?><section class="article-related" aria-labelledby="related-title"><h2 id="related-title">Continue aprendendo</h2><div class="knowledge-grid"><?php foreach ($related as $item): ?><article class="knowledge-card"><div class="knowledge-card-body"><span class="knowledge-chip"><?= sv_blog_escape((string)$item['category']) ?></span><h3><a href="/blog/<?= rawurlencode((string)$item['slug']) ?>"><?= sv_blog_escape((string)$item['title']) ?></a></h3><p><?= sv_blog_escape((string)$item['excerpt']) ?></p><div class="knowledge-meta"><span><?= (int)$item['reading_time'] ?> min</span></div></div></article><?php endforeach; ?></div></section><?php endif; ?>
    </article>
</main>
<?php include __DIR__ . '/../includes/footer.php'; ?>
</body>
</html>

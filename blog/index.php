<?php
declare(strict_types=1);

require_once __DIR__ . '/content.php';
require_once __DIR__ . '/../includes/blog-article-repository.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: text/html; charset=UTF-8');
$query = trim((string)($_GET['q'] ?? ''));
$categoryFilter = trim((string)($_GET['categoria'] ?? ''));
$isFiltered = $query !== '' || $categoryFilter !== '';
$repository = BlogArticleRepository::fromApplicationDatabase();
$allPublished = $repository->published('', '', 200);
$articles = $repository->published($query, $categoryFilter, 30);
$categories = [];
foreach ($allPublished as $publishedArticle) {
    $category = trim((string)($publishedArticle['category'] ?? ''));
    if ($category !== '') {
        $categories[$category] = ($categories[$category] ?? 0) + 1;
    }
}
ksort($categories, SORT_NATURAL | SORT_FLAG_CASE);
$totalArticles = count($allPublished);
$pageTitle = 'Central de Conhecimento | ShopVivaliz';
$pageDescription = 'Guias de compra, organização, manutenção e cuidados para escolher melhor produtos para casa, jardim e projetos.';
$pageUrl = 'https://shopvivaliz.com.br/blog/';
$socialImage = 'https://shopvivaliz.com.br/images/logo-vivaliz.png';
$robots = $isFiltered
    ? 'noindex,follow,max-image-preview:large'
    : 'index,follow,max-image-preview:large,max-snippet:-1,max-video-preview:-1';

$blogSchema = [
    '@context' => 'https://schema.org',
    '@type' => 'Blog',
    'name' => 'Central de Conhecimento ShopVivaliz',
    'description' => $pageDescription,
    'url' => $pageUrl,
    'publisher' => [
        '@type' => 'Organization',
        'name' => 'ShopVivaliz',
        'url' => 'https://shopvivaliz.com.br/',
        'logo' => [
            '@type' => 'ImageObject',
            'url' => $socialImage,
        ],
    ],
    'blogPost' => array_map(static function (array $article): array {
        $image = trim((string)($article['image'] ?? ''));
        if ($image !== '' && !str_starts_with($image, 'http')) {
            $image = 'https://shopvivaliz.com.br/' . ltrim($image, '/');
        }

        return [
            '@type' => 'BlogPosting',
            'headline' => (string)($article['title'] ?? ''),
            'description' => (string)($article['excerpt'] ?? ''),
            'url' => 'https://shopvivaliz.com.br/blog/' . rawurlencode((string)($article['slug'] ?? '')),
            'datePublished' => (string)($article['published_at'] ?? ''),
            'dateModified' => (string)($article['updated_at'] ?? $article['published_at'] ?? ''),
            'image' => $image,
        ];
    }, array_slice($allPublished, 0, 20)),
];
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="<?= sv_blog_escape($pageDescription) ?>">
    <meta name="robots" content="<?= sv_blog_escape($robots) ?>">
    <title><?= sv_blog_escape($pageTitle) ?></title>
    <link rel="canonical" href="<?= sv_blog_escape($pageUrl) ?>">
    <link rel="alternate" type="application/rss+xml" title="Central de Conhecimento ShopVivaliz" href="https://shopvivaliz.com.br/blog/feed.xml">
    <meta property="og:type" content="website">
    <meta property="og:site_name" content="ShopVivaliz">
    <meta property="og:title" content="<?= sv_blog_escape($pageTitle) ?>">
    <meta property="og:description" content="<?= sv_blog_escape($pageDescription) ?>">
    <meta property="og:url" content="<?= sv_blog_escape($pageUrl) ?>">
    <meta property="og:locale" content="pt_BR">
    <meta property="og:image" content="<?= sv_blog_escape($socialImage) ?>">
    <meta property="og:image:alt" content="Logo da ShopVivaliz">
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="<?= sv_blog_escape($pageTitle) ?>">
    <meta name="twitter:description" content="<?= sv_blog_escape($pageDescription) ?>">
    <meta name="twitter:image" content="<?= sv_blog_escape($socialImage) ?>">
    <link rel="stylesheet" href="/css/responsive.css">
    <link rel="stylesheet" href="/public/assets/blog/blog.css?v=2026-07-31-2">
    <script type="application/ld+json"><?= json_encode($blogSchema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?></script>
    <?php include __DIR__ . '/../includes/head-analytics.php'; ?>
</head>
<body>
<?php $svNavCurrent = 'blog'; include __DIR__ . '/../includes/navbar.php'; ?>
<main>
    <section class="knowledge-hero">
        <div class="container">
            <div class="knowledge-hero-card">
                <span class="knowledge-eyebrow">Central de Conhecimento ShopVivaliz</span>
                <h1>Escolhas melhores começam com informação confiável.</h1>
                <p>Guias práticos, cuidados, comparativos e respostas para ajudar você a comprar com segurança e aproveitar melhor cada produto.</p>
                <form class="knowledge-search" method="get" action="/blog/" role="search">
                    <label class="sr-only" for="knowledge-q">Buscar na Central de Conhecimento</label>
                    <input id="knowledge-q" type="search" name="q" value="<?= sv_blog_escape($query) ?>" placeholder="Busque por rodízio, ferragens, organização..." enterkeyhint="search">
                    <?php if ($categoryFilter !== ''): ?>
                        <input type="hidden" name="categoria" value="<?= sv_blog_escape($categoryFilter) ?>">
                    <?php endif; ?>
                    <button type="submit">Buscar</button>
                </form>
            </div>
        </div>
    </section>

    <div class="container knowledge-layout">
        <section aria-labelledby="latest-title">
            <span class="knowledge-eyebrow"><?= $isFiltered ? 'Resultados filtrados' : 'Conteúdos recentes' ?></span>
            <h2 id="latest-title"><?= count($articles) ?> de <?= $totalArticles ?> artigos disponíveis</h2>
            <?php if ($isFiltered): ?>
                <p class="knowledge-filter-summary" role="status">
                    Filtro ativo<?= $query !== '' ? ': busca por “' . sv_blog_escape($query) . '”' : '' ?><?= $categoryFilter !== '' ? ' em ' . sv_blog_escape($categoryFilter) : '' ?>.
                    <a href="/blog/">Limpar filtros</a>
                </p>
            <?php endif; ?>
            <?php if ($articles === []): ?>
                <div class="knowledge-panel">
                    <h2>Nenhum artigo encontrado</h2>
                    <p>Tente buscar por termos como ferramentas, rodízio, ferragens, organização ou cozinha.</p>
                </div>
            <?php else: ?>
                <div class="knowledge-grid">
                    <?php foreach ($articles as $article): ?>
                        <article class="knowledge-card">
                            <a class="knowledge-card-media" href="/blog/<?= rawurlencode((string)$article['slug']) ?>" aria-label="Ler <?= sv_blog_escape((string)$article['title']) ?>">
                                <img src="<?= sv_blog_escape((string)$article['image']) ?>" alt="<?= sv_blog_escape((string)$article['image_alt']) ?>" loading="lazy" decoding="async" width="720" height="405">
                            </a>
                            <div class="knowledge-card-body">
                                <span class="knowledge-chip"><?= sv_blog_escape((string)$article['category']) ?></span>
                                <h2><a href="/blog/<?= rawurlencode((string)$article['slug']) ?>"><?= sv_blog_escape((string)$article['title']) ?></a></h2>
                                <p><?= sv_blog_escape((string)$article['excerpt']) ?></p>
                                <div class="knowledge-meta">
                                    <span><?= (int)$article['reading_time'] ?> min de leitura</span>
                                    <time datetime="<?= sv_blog_escape((string)$article['published_at']) ?>"><?= sv_blog_escape(sv_blog_date((string)$article['published_at'])) ?></time>
                                </div>
                            </div>
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>

        <aside class="knowledge-sidebar" aria-label="Navegação da Central de Conhecimento">
            <div class="knowledge-panel">
                <h2>Categorias</h2>
                <ul class="knowledge-category-list">
                    <li><a href="/blog/"<?= $categoryFilter === '' ? ' aria-current="page"' : '' ?>><span>Todas</span><strong><?= $totalArticles ?></strong></a></li>
                    <?php foreach ($categories as $category => $count): ?>
                        <li>
                            <a href="/blog/?categoria=<?= rawurlencode((string)$category) ?><?= $query !== '' ? '&q=' . rawurlencode($query) : '' ?>"<?= strcasecmp($categoryFilter, (string)$category) === 0 ? ' aria-current="page"' : '' ?>>
                                <span><?= sv_blog_escape((string)$category) ?></span><strong><?= (int)$count ?></strong>
                            </a>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
            <div class="knowledge-panel">
                <h2>Acompanhe as novidades</h2>
                <p>Receba os artigos publicados em seu leitor de notícias preferido.</p>
                <a href="/blog/feed.xml" type="application/rss+xml">Assinar feed RSS</a>
            </div>
            <div class="knowledge-panel">
                <h2>Precisa de ajuda?</h2>
                <p>A Liz pode ajudar a encontrar produtos e responder dúvidas durante sua navegação.</p>
                <a href="/catalogo/">Explorar catálogo</a>
            </div>
        </aside>
    </div>
</main>
<?php include __DIR__ . '/../includes/footer.php'; ?>
</body>
</html>
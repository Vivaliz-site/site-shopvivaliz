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
$articles = $repository->published($query, $categoryFilter, 60);
$categories = [];
foreach ($allPublished as $publishedArticle) {
    $category = trim((string)($publishedArticle['category'] ?? ''));
    if ($category !== '') {
        $categories[$category] = ($categories[$category] ?? 0) + 1;
    }
}
ksort($categories, SORT_NATURAL | SORT_FLAG_CASE);
$totalArticles = count($allPublished);

$topicShortcuts = $categories;
arsort($topicShortcuts, SORT_NUMERIC);
$topicShortcuts = array_slice($topicShortcuts, 0, 6, true);

$featuredArticle = null;
$guideArticles = [];
$recentArticles = [];
if (!$isFiltered && $allPublished !== []) {
    foreach ($allPublished as $candidate) {
        if (!empty($candidate['featured'])) {
            $featuredArticle = $candidate;
            break;
        }
    }
    $featuredArticle ??= $allPublished[0];
    $featuredSlug = (string)($featuredArticle['slug'] ?? '');

    foreach ($allPublished as $candidate) {
        if ((string)($candidate['slug'] ?? '') === $featuredSlug) {
            continue;
        }
        $title = (string)($candidate['title'] ?? '');
        if (preg_match('/como escolher|guia|comparativo|quando usar|o que observar/ui', $title) === 1) {
            $guideArticles[] = $candidate;
            if (count($guideArticles) >= 6) {
                break;
            }
        }
    }

    $guideSlugs = array_fill_keys(array_map(static fn(array $item): string => (string)($item['slug'] ?? ''), $guideArticles), true);
    foreach ($allPublished as $candidate) {
        $candidateSlug = (string)($candidate['slug'] ?? '');
        if ($candidateSlug === $featuredSlug || isset($guideSlugs[$candidateSlug])) {
            continue;
        }
        $recentArticles[] = $candidate;
        if (count($recentArticles) >= 9) {
            break;
        }
    }
}

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

$renderCard = static function (array $article): void {
    $slug = rawurlencode((string)($article['slug'] ?? ''));
    ?>
    <article class="knowledge-card">
        <a class="knowledge-card-media" href="/blog/<?= $slug ?>" aria-label="Ler <?= sv_blog_escape((string)$article['title']) ?>">
            <img src="<?= sv_blog_escape((string)$article['image']) ?>" alt="<?= sv_blog_escape((string)$article['image_alt']) ?>" loading="lazy" decoding="async" width="720" height="405">
        </a>
        <div class="knowledge-card-body">
            <span class="knowledge-chip"><?= sv_blog_escape((string)$article['category']) ?></span>
            <h3><a href="/blog/<?= $slug ?>"><?= sv_blog_escape((string)$article['title']) ?></a></h3>
            <p><?= sv_blog_escape((string)$article['excerpt']) ?></p>
            <div class="knowledge-meta">
                <span><?= (int)$article['reading_time'] ?> min de leitura</span>
                <?php if (!empty($article['published_at'])): ?><time datetime="<?= sv_blog_escape((string)$article['published_at']) ?>"><?= sv_blog_escape(sv_blog_date((string)$article['published_at'])) ?></time><?php endif; ?>
            </div>
            <a class="knowledge-card-cta" href="/blog/<?= $slug ?>">Ler guia <span aria-hidden="true">→</span></a>
        </div>
    </article>
    <?php
};
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
    <link rel="stylesheet" href="/public/assets/blog/blog.css?v=2026-09-11-knowledge-1">
    <!-- Rodada 10 (2026-08-19): JSON_HEX_TAG|JSON_HEX_AMP -- ver R10-1 em catalogo.php -->
    <script type="application/ld+json"><?= json_encode($blogSchema, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
    <?php include __DIR__ . '/../includes/head-analytics.php'; ?>
</head>
<body>
<?php $svNavCurrent = 'blog'; include __DIR__ . '/../includes/navbar.php'; ?>
<main>
    <section class="knowledge-hero">
        <div class="container">
            <div class="knowledge-hero-card">
                <span class="knowledge-eyebrow">Central de Conhecimento ShopVivaliz</span>
                <h1>Resolva a dúvida antes de escolher o produto.</h1>
                <p>Guias práticos para comparar opções, medir corretamente, evitar erros de instalação e cuidar melhor do que você já tem.</p>
                <form class="knowledge-search" method="get" action="/blog/" role="search">
                    <label class="sr-only" for="knowledge-q">Buscar na Central de Conhecimento</label>
                    <input id="knowledge-q" type="search" name="q" value="<?= sv_blog_escape($query) ?>" placeholder="Ex.: rodízio para piso, bucha para parede, organização..." enterkeyhint="search">
                    <?php if ($categoryFilter !== ''): ?>
                        <input type="hidden" name="categoria" value="<?= sv_blog_escape($categoryFilter) ?>">
                    <?php endif; ?>
                    <button type="submit">Encontrar guia</button>
                </form>
                <?php if (!$isFiltered && $totalArticles > 0): ?>
                    <p class="knowledge-hero-proof"><strong><?= $totalArticles ?></strong> conteúdos disponíveis para ajudar na decisão, uso e manutenção.</p>
                <?php endif; ?>
            </div>
        </div>
    </section>

    <?php if ($isFiltered): ?>
        <div class="container knowledge-filtered">
            <div class="knowledge-section-head">
                <div>
                    <span class="knowledge-eyebrow">Resultados filtrados</span>
                    <h2><?= count($articles) ?> resultado<?= count($articles) === 1 ? '' : 's' ?></h2>
                </div>
                <a class="knowledge-clear-filter" href="/blog/">Limpar filtros</a>
            </div>
            <p class="knowledge-filter-summary" role="status">
                <?= $query !== '' ? 'Busca por “' . sv_blog_escape($query) . '”' : 'Todos os termos' ?><?= $categoryFilter !== '' ? ' em ' . sv_blog_escape($categoryFilter) : '' ?>.
            </p>
            <?php if ($articles === []): ?>
                <div class="knowledge-empty">
                    <h2>Nenhum guia encontrado</h2>
                    <p>Tente um termo mais amplo ou escolha um dos assuntos abaixo.</p>
                    <a href="/blog/">Ver todos os conteúdos</a>
                </div>
            <?php else: ?>
                <div class="knowledge-grid knowledge-grid--results">
                    <?php foreach ($articles as $article) { $renderCard($article); } ?>
                </div>
            <?php endif; ?>
        </div>
    <?php else: ?>
        <?php if ($featuredArticle !== null): ?>
            <section class="container knowledge-featured" aria-labelledby="featured-title">
                <a class="knowledge-featured-media" href="/blog/<?= rawurlencode((string)$featuredArticle['slug']) ?>" aria-label="Abrir <?= sv_blog_escape((string)$featuredArticle['title']) ?>">
                    <img src="<?= sv_blog_escape((string)$featuredArticle['image']) ?>" alt="<?= sv_blog_escape((string)$featuredArticle['image_alt']) ?>" width="960" height="540" loading="eager" fetchpriority="high" decoding="async">
                </a>
                <div class="knowledge-featured-body">
                    <span class="knowledge-eyebrow">Leitura recomendada</span>
                    <span class="knowledge-chip"><?= sv_blog_escape((string)$featuredArticle['category']) ?></span>
                    <h2 id="featured-title"><a href="/blog/<?= rawurlencode((string)$featuredArticle['slug']) ?>"><?= sv_blog_escape((string)$featuredArticle['title']) ?></a></h2>
                    <p><?= sv_blog_escape((string)$featuredArticle['excerpt']) ?></p>
                    <div class="knowledge-meta"><span><?= (int)$featuredArticle['reading_time'] ?> min de leitura</span><?php if (!empty($featuredArticle['published_at'])): ?><time datetime="<?= sv_blog_escape((string)$featuredArticle['published_at']) ?>"><?= sv_blog_escape(sv_blog_date((string)$featuredArticle['published_at'])) ?></time><?php endif; ?></div>
                    <a class="knowledge-primary-cta" href="/blog/<?= rawurlencode((string)$featuredArticle['slug']) ?>">Ler guia completo <span aria-hidden="true">→</span></a>
                </div>
            </section>
        <?php endif; ?>

        <?php if ($topicShortcuts !== []): ?>
            <section class="container knowledge-topics" aria-labelledby="topics-title">
                <div class="knowledge-section-head">
                    <div>
                        <span class="knowledge-eyebrow">Comece pela sua dúvida</span>
                        <h2 id="topics-title">Explore por assunto</h2>
                    </div>
                </div>
                <div class="knowledge-topic-list">
                    <?php foreach ($topicShortcuts as $category => $count): ?>
                        <a class="knowledge-topic" href="/blog/?categoria=<?= rawurlencode((string)$category) ?>">
                            <span><?= sv_blog_escape((string)$category) ?></span>
                            <small><?= (int)$count ?> conteúdo<?= (int)$count === 1 ? '' : 's' ?></small>
                        </a>
                    <?php endforeach; ?>
                </div>
            </section>
        <?php endif; ?>

        <?php if ($guideArticles !== []): ?>
            <section class="container knowledge-guides" aria-labelledby="guides-title">
                <div class="knowledge-section-head">
                    <div>
                        <span class="knowledge-eyebrow">Decida com critério</span>
                        <h2 id="guides-title">Guias para escolher melhor</h2>
                    </div>
                    <a href="/blog/?q=como+escolher">Ver mais guias</a>
                </div>
                <div class="knowledge-grid knowledge-grid--guides">
                    <?php foreach ($guideArticles as $article) { $renderCard($article); } ?>
                </div>
            </section>
        <?php endif; ?>

        <?php if ($recentArticles !== []): ?>
            <section class="container knowledge-recent" aria-labelledby="recent-title">
                <div class="knowledge-section-head">
                    <div>
                        <span class="knowledge-eyebrow">Novos conteúdos</span>
                        <h2 id="recent-title">Publicados recentemente</h2>
                    </div>
                </div>
                <div class="knowledge-grid knowledge-grid--recent">
                    <?php foreach ($recentArticles as $article) { $renderCard($article); } ?>
                </div>
            </section>
        <?php endif; ?>

        <section class="container knowledge-assist" aria-labelledby="assist-title">
            <div>
                <span class="knowledge-eyebrow">Da dúvida para a escolha</span>
                <h2 id="assist-title">Já sabe o que precisa?</h2>
                <p>Use o catálogo para comparar as opções disponíveis. Na página de cada produto, confirme medidas, aplicação e especificações antes de comprar.</p>
            </div>
            <div class="knowledge-assist-actions">
                <a class="knowledge-primary-cta" href="/catalogo/">Explorar catálogo</a>
                <a class="knowledge-secondary-link" href="/blog/feed.xml" type="application/rss+xml">Acompanhar novos artigos por RSS</a>
            </div>
        </section>
    <?php endif; ?>
</main>
<?php include __DIR__ . '/../includes/footer.php'; ?>
</body>
</html>
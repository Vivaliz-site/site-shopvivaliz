<?php
declare(strict_types=1);

require_once __DIR__ . '/content.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: text/html; charset=UTF-8');
$articles = sv_blog_articles();
$categories = sv_blog_categories();
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Guias de compra, organização, manutenção e cuidados para escolher melhor produtos para casa, jardim e projetos.">
    <title>Central de Conhecimento | ShopVivaliz</title>
    <link rel="canonical" href="https://shopvivaliz.com.br/blog">
    <meta property="og:type" content="website">
    <meta property="og:title" content="Central de Conhecimento | ShopVivaliz">
    <meta property="og:description" content="Conteúdo prático para ajudar você a escolher, usar e conservar melhor seus produtos.">
    <meta property="og:url" content="https://shopvivaliz.com.br/blog">
    <meta property="og:locale" content="pt_BR">
    <link rel="stylesheet" href="/css/responsive.css">
    <link rel="stylesheet" href="/public/assets/blog/blog.css">
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
            </div>
        </div>
    </section>

    <div class="container knowledge-layout">
        <section aria-labelledby="latest-title">
            <span class="knowledge-eyebrow">Conteúdos recentes</span>
            <h2 id="latest-title">Aprenda antes de escolher</h2>
            <div class="knowledge-grid">
                <?php foreach ($articles as $article): ?>
                    <article class="knowledge-card">
                        <a href="/blog/<?= rawurlencode((string)$article['slug']) ?>" aria-label="Ler <?= sv_blog_escape((string)$article['title']) ?>">
                            <img src="<?= sv_blog_escape((string)$article['image']) ?>" alt="<?= sv_blog_escape((string)$article['image_alt']) ?>" loading="lazy">
                        </a>
                        <div class="knowledge-card-body">
                            <span class="knowledge-chip"><?= sv_blog_escape((string)$article['category']) ?></span>
                            <h2><a href="/blog/<?= rawurlencode((string)$article['slug']) ?>"><?= sv_blog_escape((string)$article['title']) ?></a></h2>
                            <p><?= sv_blog_escape((string)$article['excerpt']) ?></p>
                            <div class="knowledge-meta">
                                <span><?= (int)$article['reading_time'] ?> min</span>
                                <span><?= sv_blog_escape(sv_blog_date((string)$article['published_at'])) ?></span>
                            </div>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        </section>

        <aside class="knowledge-sidebar" aria-label="Navegação da Central de Conhecimento">
            <div class="knowledge-panel">
                <h2>Categorias</h2>
                <ul class="knowledge-category-list">
                    <?php foreach ($categories as $category => $count): ?>
                        <li><span><?= sv_blog_escape((string)$category) ?></span><strong><?= (int)$count ?></strong></li>
                    <?php endforeach; ?>
                </ul>
            </div>
            <div class="knowledge-panel">
                <h2>Precisa de ajuda?</h2>
                <p>A Liz pode ajudar a encontrar produtos e responder dúvidas durante sua navegação.</p>
                <a href="/catalogo">Explorar catálogo</a>
            </div>
        </aside>
    </div>
</main>
<?php include __DIR__ . '/../includes/footer.php'; ?>
</body>
</html>

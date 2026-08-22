<?php
declare(strict_types=1);

// Os artigos nasceram antes da consolidacao das URLs de diretorio e ainda
// carregam links legados sem barra final (blog/catalogo/contato). Normaliza a
// resposta inteira, inclusive URLs vindas do repositorio editorial, sem mudar
// conteudo, comentarios ou dados comerciais.
ob_start(static function (string $html): string {
    $patterns = [
        '#https://shopvivaliz\.com\.br/blog(?=(?:\?|["\']))#' => 'https://shopvivaliz.com.br/blog/',
        '#https://shopvivaliz\.com\.br/catalogo(?=(?:\?|["\']))#' => 'https://shopvivaliz.com.br/catalogo/',
        '#https://shopvivaliz\.com\.br/contato(?=(?:\?|["\']))#' => 'https://shopvivaliz.com.br/contato/',
        '#(?<=["\'])/blog(?=(?:\?|["\']))#' => '/blog/',
        '#(?<=["\'])/catalogo(?=(?:\?|["\']))#' => '/catalogo/',
        '#(?<=["\'])/contato(?=(?:\?|["\']))#' => '/contato/',
    ];
    foreach ($patterns as $pattern => $replacement) {
        $html = preg_replace($pattern, $replacement, $html) ?? $html;
    }
    return $html;
});

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/content.php';
require_once __DIR__ . '/../includes/blog-article-repository.php';
require_once __DIR__ . '/../includes/blog-comment-repository.php';
require_once __DIR__ . '/../includes/blog-seo.php';
require_once __DIR__ . '/../includes/csrf.php';

$slug = trim((string)($_GET['slug'] ?? ''));
$commentStatus = trim((string)($_GET['comentario'] ?? ''));
$repository = BlogArticleRepository::fromApplicationDatabase();
$article = $repository->findPublishedBySlug($slug);

if ($article === null) {
    http_response_code(404);
    require __DIR__ . '/../404.php';
    exit;
}

$comments = [];
try {
    $comments = BlogCommentRepository::fromApplicationDatabase()->publishedForArticle((string)$article['slug'], 50);
} catch (Throwable $commentError) {
    error_log('Falha ao carregar comentários do blog: ' . $commentError->getMessage());
}

$origin = sv_blog_seo_origin();
$canonical = $origin . '/blog/' . rawurlencode((string)$article['slug']);
$seoTitle = sv_blog_seo_title((string)$article['meta_title']);
$seoDescription = sv_blog_seo_description(
    (string)$article['meta_description'],
    (string)($article['excerpt'] ?? ''),
    (string)($article['title'] ?? '')
);
$image = trim((string)($article['image'] ?? ''));
$imageAbsolute = $image !== '' && str_starts_with($image, 'http') ? $image : $origin . '/' . ltrim($image, '/');

$schema = [
    '@context' => 'https://schema.org',
    '@type' => 'Article',
    'headline' => $article['title'],
    'description' => $seoDescription,
    'image' => $imageAbsolute,
    'datePublished' => $article['published_at'],
    'dateModified' => $article['updated_at'],
    'author' => ['@type' => 'Organization', 'name' => $article['author']],
    'publisher' => ['@type' => 'Organization', 'name' => 'ShopVivaliz', 'url' => $origin],
    'mainEntityOfPage' => ['@type' => 'WebPage', '@id' => $canonical],
    'interactionStatistic' => [
        '@type' => 'InteractionCounter',
        'interactionType' => 'https://schema.org/CommentAction',
        'userInteractionCount' => count($comments),
    ],
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
    <title><?= sv_blog_escape($seoTitle) ?></title>
    <meta name="description" content="<?= sv_blog_escape($seoDescription) ?>">
    <meta name="keywords" content="<?= sv_blog_escape(implode(', ', $article['keywords'])) ?>">
    <link rel="canonical" href="<?= sv_blog_escape($canonical) ?>">
    <link rel="alternate" type="application/rss+xml" title="Central de Conhecimento ShopVivaliz" href="/blog/feed.xml">
    <meta property="og:type" content="article">
    <meta property="og:title" content="<?= sv_blog_escape((string)$article['title']) ?>">
    <meta property="og:description" content="<?= sv_blog_escape($seoDescription) ?>">
    <meta property="og:url" content="<?= sv_blog_escape($canonical) ?>">
    <meta property="og:image" content="<?= sv_blog_escape($imageAbsolute) ?>">
    <meta property="og:locale" content="pt_BR">
    <meta name="twitter:card" content="summary_large_image">
    <link rel="stylesheet" href="/css/responsive.css">
    <link rel="stylesheet" href="/public/assets/blog/blog.css?v=2026-07-28-comments-1">
    <style>
        .article-comments-list{display:grid;gap:18px;margin:24px 0}.article-comment{border:1px solid #e5e7eb;border-radius:14px;padding:18px;background:#fff}.article-comment-head{display:flex;gap:10px;align-items:center;justify-content:space-between;flex-wrap:wrap}.article-comment-name{font-weight:800}.article-comment-date{font-size:13px;color:#6b7280}.article-comment-message{margin:12px 0 0;white-space:pre-wrap;overflow-wrap:anywhere;word-break:break-word}.article-comment-replies{display:grid;gap:12px;margin:16px 0 0 18px}.article-comment-reply{border-left:4px solid #173B63;background:#f5f8fc;border-radius:0 12px 12px 0;padding:14px;overflow:visible;min-height:0}.article-comment-reply--liz{border-left-color:#059669}.article-comment-reply-head{display:flex;gap:8px;align-items:center;flex-wrap:wrap}.article-comment-reply-head strong{flex:1 1 auto;min-width:0}.article-comment-reply p{margin:10px 0 0;white-space:pre-wrap;display:block!important;overflow:visible!important;max-height:none!important;text-overflow:clip!important;-webkit-line-clamp:unset!important;-webkit-box-orient:initial!important;overflow-wrap:anywhere;word-break:break-word}.article-comment-badge{font-size:11px;font-weight:800;text-transform:uppercase;letter-spacing:.04em;background:#dcfce7;color:#166534;border-radius:999px;padding:3px 8px}.article-comments-empty{color:#6b7280;padding:14px 0}.article-comments-note{font-size:13px;color:#6b7280}.article-comments-form button[disabled]{opacity:.65;cursor:wait}@media(max-width:640px){.article-comments{padding:20px 16px}.article-comment{padding:16px}.article-comment-replies{margin-left:0}.article-comment-reply{padding:12px 12px 14px}.article-comment-message,.article-comment-reply p{font-size:1rem;line-height:1.72}}
    </style>
    <!-- Rodada 10 (2026-08-19): JSON_HEX_TAG|JSON_HEX_AMP -- ver R10-1 em catalogo.php -->
    <script type="application/ld+json"><?= json_encode($schema, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
    <script type="application/ld+json"><?= json_encode($breadcrumbSchema, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
    <?php if ($faqSchema !== null): ?><script type="application/ld+json"><?= json_encode($faqSchema, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?></script><?php endif; ?>
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
        <section class="article-comments" id="comentarios" aria-labelledby="comments-title">
            <div class="article-comments-head">
                <h2 id="comments-title">Perguntas e comentários</h2>
                <p>Ficou com dúvida sobre este conteúdo ou quer pedir recomendação de produto? Envie sua mensagem. Comentários válidos aparecem aqui e a Liz responde publicamente.</p>
            </div>
            <?php if ($commentStatus === 'ok'): ?>
                <p class="article-comments-alert article-comments-alert--ok" role="status">Comentário publicado com sucesso.</p>
            <?php elseif ($commentStatus === 'moderacao'): ?>
                <p class="article-comments-alert article-comments-alert--ok" role="status">Comentário recebido e enviado para moderação.</p>
            <?php elseif ($commentStatus === 'limite'): ?>
                <p class="article-comments-alert article-comments-alert--error" role="alert">Muitas tentativas em pouco tempo. Aguarde alguns minutos.</p>
            <?php elseif ($commentStatus === 'erro'): ?>
                <p class="article-comments-alert article-comments-alert--error" role="alert">Não foi possível enviar agora. Revise os campos ou tente novamente.</p>
            <?php endif; ?>

            <div class="article-comments-list" aria-live="polite">
                <?php if ($comments === []): ?>
                    <p class="article-comments-empty">Ainda não há comentários publicados. Seja a primeira pessoa a participar.</p>
                <?php else: ?>
                    <?php foreach ($comments as $comment): ?>
                        <article class="article-comment">
                            <div class="article-comment-head">
                                <span class="article-comment-name"><?= sv_blog_escape((string)$comment['name']) ?></span>
                                <time class="article-comment-date" datetime="<?= sv_blog_escape((string)($comment['published_at'] ?: $comment['created_at'])) ?>"><?= sv_blog_escape(sv_blog_date((string)($comment['published_at'] ?: $comment['created_at']))) ?></time>
                            </div>
                            <p class="article-comment-message"><?= nl2br(sv_blog_escape((string)$comment['message'])) ?></p>
                            <?php if (!empty($comment['replies'])): ?>
                                <div class="article-comment-replies">
                                    <?php foreach ($comment['replies'] as $reply): ?>
                                        <div class="article-comment-reply <?= ($reply['author_type'] ?? '') === 'liz' ? 'article-comment-reply--liz' : '' ?>">
                                            <div class="article-comment-reply-head">
                                                <strong><?= sv_blog_escape((string)$reply['author_name']) ?></strong>
                                                <?php if (($reply['author_type'] ?? '') === 'liz'): ?><span class="article-comment-badge">Assistente virtual</span><?php endif; ?>
                                            </div>
                                            <p><?= nl2br(sv_blog_escape((string)$reply['message'])) ?></p>
                                            <?php if (!empty($reply['ai_generated'])): ?><p class="article-comments-note">Resposta gerada por inteligência artificial e sujeita a revisão.</p><?php endif; ?>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </article>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <form class="article-comments-form" action="/api/blog/comment.php" method="post" id="blog-comment-form">
                <?= sv_csrf_input('blog-comment-' . (string)$article['slug']) ?>
                <input type="hidden" name="artigo" value="<?= sv_blog_escape((string)$article['title']) ?>">
                <input type="hidden" name="slug" value="<?= sv_blog_escape((string)$article['slug']) ?>">
                <input type="hidden" name="url" value="<?= sv_blog_escape($canonical) ?>">
                <input type="text" name="website" value="" autocomplete="off" tabindex="-1" style="position:absolute;left:-9999px;height:1px;width:1px;opacity:0" aria-hidden="true">
                <div class="article-comments-grid">
                    <label>
                        <span>Seu nome</span>
                        <input type="text" name="nome" autocomplete="name" maxlength="120" placeholder="Como podemos te chamar?" required>
                    </label>
                    <label>
                        <span>Seu e-mail</span>
                        <input type="email" name="email" autocomplete="email" maxlength="254" placeholder="voce@exemplo.com" required>
                    </label>
                </div>
                <label>
                    <span>Sua pergunta ou comentário</span>
                    <textarea name="mensagem" rows="5" minlength="3" maxlength="2000" placeholder="Escreva sua dúvida, comentário ou o que você quer encontrar no site." required></textarea>
                </label>
                <p class="article-comments-note">Seu e-mail não será exibido publicamente. Evite inserir dados pessoais, número de pedido, documentos ou informações bancárias no comentário.</p>
                <div class="article-comments-actions">
                    <button type="submit">Enviar comentário</button>
                    <a href="/contato">Abrir atendimento completo</a>
                </div>
            </form>
        </section>
        <?php if ($faqItems !== []): ?><section class="article-faq" aria-labelledby="faq-title"><h2 id="faq-title">Perguntas frequentes</h2><?php foreach ($faqItems as $faq): ?><details><summary><?= sv_blog_escape((string)$faq['question']) ?></summary><p><?= sv_blog_escape((string)$faq['answer']) ?></p></details><?php endforeach; ?></section><?php endif; ?>
        <?php if ($related !== []): ?><section class="article-related" aria-labelledby="related-title"><h2>Continue aprendendo</h2><div class="knowledge-grid"><?php foreach ($related as $item): ?><article class="knowledge-card"><div class="knowledge-card-body"><span class="knowledge-chip"><?= sv_blog_escape((string)$item['category']) ?></span><h3><a href="/blog/<?= rawurlencode((string)$item['slug']) ?>"><?= sv_blog_escape((string)$item['title']) ?></a></h3><p><?= sv_blog_escape((string)$item['excerpt']) ?></p><div class="knowledge-meta"><span><?= (int)$item['reading_time'] ?> min</span></div></div></article><?php endforeach; ?></div></section><?php endif; ?>
    </article>
</main>
<script>
(function(){var form=document.getElementById('blog-comment-form');if(!form)return;form.addEventListener('submit',function(){var button=form.querySelector('button[type="submit"]');if(button){button.disabled=true;button.textContent='Enviando...';}});})();
</script>
<?php include __DIR__ . '/../includes/footer.php'; ?>
</body>
</html>
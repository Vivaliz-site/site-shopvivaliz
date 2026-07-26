<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/blog-article-repository.php';
require_once __DIR__ . '/../includes/liz-knowledge-context.php';

function assert_true(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$repository = new BlogArticleRepository(null);
$articles = $repository->published('', '', 200);
assert_true(is_array($articles), 'published() deve retornar array');
assert_true(count($articles) > 0, 'fallback estático deve possuir ao menos um artigo');

foreach ($articles as $article) {
    assert_true(trim((string)($article['slug'] ?? '')) !== '', 'artigo publicado precisa de slug');
    assert_true(trim((string)($article['title'] ?? '')) !== '', 'artigo publicado precisa de título');
}

$firstSlug = (string)$articles[0]['slug'];
$found = $repository->findPublishedBySlug($firstSlug);
assert_true(is_array($found), 'findPublishedBySlug() deve encontrar conteúdo do fallback');
assert_true((string)$found['slug'] === $firstSlug, 'slug retornado deve ser o solicitado');

$context = sv_liz_knowledge_context((string)$articles[0]['title'], 3);
assert_true(is_array($context), 'contexto da Liz deve retornar array');
assert_true(count($context) >= 1, 'Liz deve localizar artigo pelo título');
assert_true(str_starts_with((string)$context[0]['url'], '/blog/'), 'URL da Liz deve apontar para /blog/');

$prompt = sv_liz_knowledge_prompt_block($context);
assert_true(str_contains($prompt, 'CONTEUDOS PUBLICADOS'), 'prompt deve indicar somente conteúdos publicados');

fwrite(STDOUT, "OK blog editorial smoke\n");

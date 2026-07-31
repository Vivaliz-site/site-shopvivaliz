<?php
declare(strict_types=1);

function bc_assert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$root = dirname(__DIR__);
$required = [
    'api/blog/comment.php',
    'includes/blog-comment-repository.php',
    'includes/liz-blog-comment-responder.php',
    'admin/blog-comments.php',
    'database/migrations/20260728_create_blog_comments.sql',
    'scripts/migrate-blog-comments.php',
];
foreach ($required as $path) {
    bc_assert(is_file($root . '/' . $path), "arquivo obrigatório ausente: {$path}");
}

$article = (string)file_get_contents($root . '/blog/artigo.php');
bc_assert(str_contains($article, "id=\"comentarios\""), 'artigo deve possuir âncora de comentários');
bc_assert(str_contains($article, 'BlogCommentRepository'), 'artigo deve carregar comentários publicados');
bc_assert(str_contains($article, 'sv_csrf_input'), 'formulário deve usar CSRF');
bc_assert(str_contains($article, 'Resposta gerada por inteligência artificial'), 'resposta da Liz deve ser identificada como IA');

$endpoint = (string)file_get_contents($root . '/api/blog/comment.php');
bc_assert(str_contains($endpoint, 'bc_rate_limit'), 'endpoint deve aplicar rate limit');
bc_assert(str_contains($endpoint, 'website'), 'endpoint deve validar honeypot');
bc_assert(str_contains($endpoint, 'bc_encrypt_email'), 'endpoint deve proteger e-mail');
bc_assert(str_contains($endpoint, 'LizBlogCommentResponder'), 'endpoint deve integrar a Liz');

$migration = (string)file_get_contents($root . '/database/migrations/20260728_create_blog_comments.sql');
bc_assert(str_contains($migration, 'CREATE TABLE IF NOT EXISTS blog_comments'), 'migração deve criar comentários');
bc_assert(str_contains($migration, 'CREATE TABLE IF NOT EXISTS blog_comment_replies'), 'migração deve criar respostas');
bc_assert(str_contains($migration, 'CREATE TABLE IF NOT EXISTS blog_comment_audit'), 'migração deve criar auditoria');

fwrite(STDOUT, "OK blog comments smoke\n");
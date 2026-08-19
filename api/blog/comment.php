<?php
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

date_default_timezone_set('America/Sao_Paulo');

require_once __DIR__ . '/../../includes/csrf.php';
require_once __DIR__ . '/../../includes/blog-article-repository.php';
require_once __DIR__ . '/../../includes/blog-comment-repository.php';
require_once __DIR__ . '/../../includes/liz-blog-comment-responder.php';

function bc_redirect(string $slug, string $status): never
{
    $target = '/blog/' . rawurlencode($slug) . '?comentario=' . rawurlencode($status) . '#comentarios';
    header('Location: ' . $target, true, 303);
    exit;
}

function bc_length(string $value): int
{
    return function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
}

function bc_clean_text(string $value): string
{
    $value = trim(strip_tags($value));
    return trim(preg_replace('/\s+/u', ' ', $value) ?? '');
}

function bc_load_env(): void
{
    foreach ([dirname(__DIR__, 2) . '/.env.local', dirname(__DIR__, 2) . '/.env'] as $file) {
        if (!is_file($file) || !is_readable($file)) continue;
        foreach (file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) continue;
            [$name, $value] = array_map('trim', explode('=', $line, 2));
            if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name)) continue;
            if ((str_starts_with($value, '"') && str_ends_with($value, '"')) || (str_starts_with($value, "'") && str_ends_with($value, "'"))) {
                $value = substr($value, 1, -1);
            }
            if (getenv($name) === false || getenv($name) === '') putenv($name . '=' . $value);
        }
    }
}

function bc_encrypt_email(string $email): ?string
{
    $key = trim((string)(getenv('BLOG_COMMENT_ENCRYPTION_KEY') ?: getenv('APP_KEY') ?: ''));
    if ($key === '' || !function_exists('openssl_encrypt')) return null;
    $keyBin = hash('sha256', $key, true);
    $iv = random_bytes(12);
    $tag = '';
    $cipher = openssl_encrypt($email, 'aes-256-gcm', $keyBin, OPENSSL_RAW_DATA, $iv, $tag);
    if (!is_string($cipher)) return null;
    return base64_encode($iv . $tag . $cipher);
}

function bc_rate_limit(string $ipHash): bool
{
    // Rodada 9 (2026-08-19): as duas saidas de erro abaixo devolviam `true`
    // (permite) quando o diretorio/arquivo de rate limit nao podia ser
    // aberto -- ou seja, a falha do proprio mecanismo de limite virava uma
    // forma de burla-lo. Cada comentario aprovado dispara uma chamada de IA
    // paga sincrona (LizBlogCommentResponder); fail-open aqui multiplica
    // custo com trafego hostil. Trocado pra fail-closed (nega o comentario
    // em vez de aceitar sem limite). Ver R9-9 no relatorio da Rodada 9.
    $dir = dirname(__DIR__, 2) . '/storage/blog-comment-rate-limit';
    if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) return false;
    $file = $dir . '/' . $ipHash . '.json';
    $fp = fopen($file, 'c+');
    if ($fp === false) return false;
    flock($fp, LOCK_EX);
    $raw = stream_get_contents($fp);
    $data = is_string($raw) ? json_decode($raw, true) : null;
    $data = is_array($data) ? $data : ['window' => time(), 'count' => 0];
    if (time() - (int)$data['window'] > 600) $data = ['window' => time(), 'count' => 0];
    $data['count'] = (int)$data['count'] + 1;
    ftruncate($fp, 0);
    rewind($fp);
    fwrite($fp, json_encode($data));
    fflush($fp);
    flock($fp, LOCK_UN);
    fclose($fp);
    return $data['count'] <= 5;
}

function bc_moderate(string $message): array
{
    $lower = function_exists('mb_strtolower') ? mb_strtolower($message, 'UTF-8') : strtolower($message);
    $urlCount = preg_match_all('/(?:https?:\/\/|www\.)/i', $message);
    if ($urlCount !== false && $urlCount > 1) return ['status' => 'spam', 'reason' => 'links_excessivos'];
    if (preg_match('/<\s*(script|iframe|object|embed|style)|javascript:/i', $message)) return ['status' => 'spam', 'reason' => 'conteudo_ativo'];
    foreach (['cassino online','apostas garantidas','ganhe dinheiro rápido','seo backlink','telegram investimento'] as $term) {
        if (str_contains($lower, $term)) return ['status' => 'spam', 'reason' => 'padrao_spam'];
    }
    foreach (['ameaça','vou matar','racista','nazista'] as $term) {
        if (str_contains($lower, $term)) return ['status' => 'pending', 'reason' => 'revisao_conteudo'];
    }
    return ['status' => 'published', 'reason' => null];
}

bc_load_env();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit('Método não permitido');
}

$slug = bc_clean_text((string)($_POST['slug'] ?? ''));
if ($slug === '') {
    http_response_code(400);
    exit('Artigo inválido');
}

if (!sv_csrf_valid('blog-comment-' . $slug, $_POST['csrf_token'] ?? '')) {
    bc_redirect($slug, 'erro');
}

if (trim((string)($_POST['website'] ?? '')) !== '') {
    bc_redirect($slug, 'ok');
}

$name = bc_clean_text((string)($_POST['nome'] ?? ''));
$email = strtolower(trim((string)($_POST['email'] ?? '')));
$message = bc_clean_text((string)($_POST['mensagem'] ?? ''));

if (bc_length($name) < 2 || bc_length($name) > 120 || !filter_var($email, FILTER_VALIDATE_EMAIL) || bc_length($message) < 3 || bc_length($message) > 2000) {
    bc_redirect($slug, 'erro');
}

$articleRepository = BlogArticleRepository::fromApplicationDatabase();
$article = $articleRepository->findPublishedBySlug($slug);
if ($article === null) {
    http_response_code(404);
    exit('Artigo não encontrado');
}

$ip = (string)($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
$privacySalt = trim((string)(getenv('BLOG_COMMENT_HASH_SALT') ?: getenv('APP_KEY') ?: 'shopvivaliz-blog-comments'));
$ipHash = hash_hmac('sha256', $ip, $privacySalt);
if (!bc_rate_limit($ipHash)) {
    bc_redirect($slug, 'limite');
}

$moderation = bc_moderate($message);
$publishedAt = $moderation['status'] === 'published' ? gmdate('Y-m-d H:i:s') : null;
$repository = BlogCommentRepository::fromApplicationDatabase();

try {
    $commentId = $repository->create([
        'article_slug' => $slug,
        'name' => $name,
        'email_encrypted' => bc_encrypt_email($email),
        'email_hash' => hash_hmac('sha256', $email, $privacySalt),
        'message' => $message,
        'status' => $moderation['status'],
        'moderation_reason' => $moderation['reason'],
        'ip_hash' => $ipHash,
        'user_agent_hash' => hash('sha256', (string)($_SERVER['HTTP_USER_AGENT'] ?? '')),
        'published_at' => $publishedAt,
    ]);
    $repository->audit($commentId, 'system', null, 'comment_created', ['status' => $moderation['status']]);

    if ($moderation['status'] === 'published') {
        try {
            $reply = (new LizBlogCommentResponder())->reply($article, $message);
            $repository->addReply(
                $commentId,
                'liz',
                'Liz — Assistente Virtual da ShopVivaliz',
                (string)$reply['message'],
                (bool)$reply['ai_generated'],
                $reply['provider'] !== null ? (string)$reply['provider'] : null,
                $reply['model'] !== null ? (string)$reply['model'] : null
            );
            $repository->audit($commentId, 'liz', null, 'reply_created', ['provider' => $reply['provider'], 'ai_generated' => $reply['ai_generated']]);
        } catch (Throwable $replyError) {
            error_log('Falha ao gerar resposta da Liz para comentário #' . $commentId . ': ' . $replyError->getMessage());
            $repository->audit($commentId, 'system', null, 'liz_reply_failed');
        }
    }
} catch (Throwable $error) {
    error_log('Falha no comentário do blog: ' . $error->getMessage());
    bc_redirect($slug, 'erro');
}

bc_redirect($slug, $moderation['status'] === 'published' ? 'ok' : 'moderacao');
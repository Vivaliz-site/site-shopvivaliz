<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$expected = (string)(getenv('BLOG_PUBLISH_TOKEN') ?: '');
$received = (string)($_SERVER['HTTP_X_BLOG_PUBLISH_TOKEN'] ?? '');
if ($expected === '' || $received === '' || !hash_equals($expected, $received)) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'unauthorized']);
    exit;
}

require_once __DIR__ . '/../../includes/blog-article-repository.php';
$repository = BlogArticleRepository::fromApplicationDatabase();
if (!$repository->isDatabaseAvailable()) {
    http_response_code(503);
    echo json_encode(['ok' => false, 'error' => 'database_unavailable']);
    exit;
}

$count = $repository->publishDue();
echo json_encode([
    'ok' => true,
    'published' => $count,
    'executed_at' => gmdate('c'),
], JSON_UNESCAPED_SLASHES);

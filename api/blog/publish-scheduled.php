<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$isCli = PHP_SAPI === 'cli';
$expected = (string)(getenv('BLOG_PUBLISH_TOKEN') ?: '');
$received = (string)($_SERVER['HTTP_X_BLOG_PUBLISH_TOKEN'] ?? '');
if (!$isCli && ($expected === '' || $received === '' || !hash_equals($expected, $received))) {
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

$queueDepth = (int)(getenv('BLOG_AUTOMATION_QUEUE_DEPTH') ?: 9);
$queue = $repository->ensureAutonomousQueue($queueDepth);
$count = $repository->publishDue();
echo json_encode([
    'ok' => true,
    'queued' => $queue['queued'] ?? 0,
    'scheduled_future' => $queue['future'] ?? 0,
    'queue_target' => $queue['target'] ?? $queueDepth,
    'queue_errors' => $queue['errors'] ?? [],
    'published' => $count,
    'executed_at' => gmdate('c'),
], JSON_UNESCAPED_SLASHES);

<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: public, max-age=300');

require_once __DIR__ . '/../includes/blog-article-repository.php';

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method !== 'GET') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Método não permitido.'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$query = trim((string)($_GET['q'] ?? ''));
$category = trim((string)($_GET['categoria'] ?? ''));
$limit = min(10, max(1, (int)($_GET['limit'] ?? 5)));
$articles = BlogArticleRepository::fromApplicationDatabase()->published($query, $category, $limit);

$items = array_map(static function (array $article): array {
    return [
        'slug' => (string)$article['slug'],
        'title' => (string)$article['title'],
        'excerpt' => (string)$article['excerpt'],
        'category' => (string)$article['category'],
        'url' => '/blog/' . rawurlencode((string)$article['slug']),
        'reading_time' => (int)$article['reading_time'],
        'related_products_url' => (string)$article['related_products_url'],
        'keywords' => array_values($article['keywords'] ?? []),
    ];
}, $articles);

echo json_encode([
    'ok' => true,
    'query' => $query,
    'category' => $category,
    'count' => count($items),
    'items' => $items,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

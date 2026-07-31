<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../blog/content.php';

$db = Database::getInstance()->getConnection();
$migration = file_get_contents(__DIR__ . '/../database/migrations/20260725_create_blog_articles.sql');
if (!is_string($migration) || trim($migration) === '') {
    fwrite(STDERR, "Migration SQL ausente.\n");
    exit(1);
}
if (!$db->query($migration)) {
    fwrite(STDERR, "Falha ao criar tabela editorial.\n");
    exit(1);
}

$sql = "INSERT INTO blog_articles
(slug,title,excerpt,category,image_url,image_alt,content_json,faq_json,keywords_json,meta_title,meta_description,related_products_url,author,status,featured,reading_time,published_at)
VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,'published',?,?,?)
ON DUPLICATE KEY UPDATE
 title=VALUES(title), excerpt=VALUES(excerpt), category=VALUES(category), image_url=VALUES(image_url), image_alt=VALUES(image_alt),
 content_json=VALUES(content_json), faq_json=VALUES(faq_json), keywords_json=VALUES(keywords_json), meta_title=VALUES(meta_title),
 meta_description=VALUES(meta_description), related_products_url=VALUES(related_products_url), author=VALUES(author),
 featured=VALUES(featured), reading_time=VALUES(reading_time), updated_at=CURRENT_TIMESTAMP";
$stmt = $db->prepare($sql);
if (!$stmt) {
    fwrite(STDERR, "Falha ao preparar importacao.\n");
    exit(1);
}

$count = 0;
foreach (sv_blog_articles() as $article) {
    $slug = (string)$article['slug'];
    $title = (string)$article['title'];
    $excerpt = (string)$article['excerpt'];
    $category = (string)$article['category'];
    $image = (string)$article['image'];
    $imageAlt = (string)$article['image_alt'];
    $content = json_encode($article['content'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $faq = json_encode($article['faq'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $keywords = json_encode($article['keywords'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $metaTitle = (string)$article['meta_title'];
    $metaDescription = (string)$article['meta_description'];
    $related = (string)$article['related_products_url'];
    $author = (string)($article['author'] ?? 'Equipe ShopVivaliz');
    $featured = !empty($article['featured']) ? 1 : 0;
    $readingTime = (int)($article['reading_time'] ?? 5);
    $publishedAt = (string)($article['published_at'] ?? date('Y-m-d')) . ' 00:00:00';
    $stmt->bind_param('sssssssssssssiis', $slug, $title, $excerpt, $category, $image, $imageAlt, $content, $faq, $keywords, $metaTitle, $metaDescription, $related, $author, $featured, $readingTime, $publishedAt);
    if (!$stmt->execute()) {
        fwrite(STDERR, "Falha ao importar {$slug}.\n");
        exit(1);
    }
    $count++;
}
$stmt->close();
fwrite(STDOUT, "Artigos processados: {$count}\n");

<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/blog-article-repository.php';

header('Content-Type: application/rss+xml; charset=UTF-8');
header('Cache-Control: public, max-age=900');

$repository = BlogArticleRepository::fromApplicationDatabase();
$articles = $repository->published('', '', 50);
$base = 'https://shopvivaliz.com.br';

function rss_escape(string $value): string
{
    return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
}

function rss_date(?string $value): string
{
    if (!$value) {
        return gmdate(DATE_RSS);
    }
    $timestamp = strtotime($value . (strlen($value) <= 10 ? ' 12:00:00 UTC' : ' UTC'));
    return $timestamp ? gmdate(DATE_RSS, $timestamp) : gmdate(DATE_RSS);
}

echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
?>
<rss version="2.0" xmlns:atom="http://www.w3.org/2005/Atom">
<channel>
    <title>Central de Conhecimento ShopVivaliz</title>
    <link><?= rss_escape($base . '/blog') ?></link>
    <description>Guias, cuidados e comparativos para escolher e usar melhor produtos para casa, jardim e projetos.</description>
    <language>pt-BR</language>
    <atom:link href="<?= rss_escape($base . '/blog/feed.xml') ?>" rel="self" type="application/rss+xml" />
    <lastBuildDate><?= rss_date($articles[0]['updated_at'] ?? $articles[0]['published_at'] ?? null) ?></lastBuildDate>
<?php foreach ($articles as $article):
    $slug = trim((string)($article['slug'] ?? ''));
    if ($slug === '') {
        continue;
    }
    $url = $base . '/blog/' . rawurlencode($slug);
?>
    <item>
        <title><?= rss_escape((string)($article['title'] ?? '')) ?></title>
        <link><?= rss_escape($url) ?></link>
        <guid isPermaLink="true"><?= rss_escape($url) ?></guid>
        <description><?= rss_escape((string)($article['excerpt'] ?? '')) ?></description>
        <category><?= rss_escape((string)($article['category'] ?? '')) ?></category>
        <pubDate><?= rss_date((string)($article['published_at'] ?? $article['updated_at'] ?? '')) ?></pubDate>
    </item>
<?php endforeach; ?>
</channel>
</rss>

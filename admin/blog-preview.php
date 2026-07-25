<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/admin-guard.php';
require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/../config/database.php';

function bp_esc(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function bp_safe_url(string $value): string
{
    $value = trim($value);
    if ($value === '') {
        return '';
    }
    if (str_starts_with($value, '/')) {
        return $value;
    }
    $parts = parse_url($value);
    if (!is_array($parts) || !in_array(strtolower((string)($parts['scheme'] ?? '')), ['http', 'https'], true)) {
        return '';
    }
    return $value;
}

$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT) ?: 0;
if ($id <= 0) {
    http_response_code(400);
    exit('Artigo inválido.');
}

$db = Database::getInstance()->getConnection();
$stmt = $db->prepare('SELECT * FROM blog_articles WHERE id = ? LIMIT 1');
if (!$stmt) {
    http_response_code(500);
    exit('Não foi possível carregar o artigo.');
}
$stmt->bind_param('i', $id);
$stmt->execute();
$article = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$article) {
    http_response_code(404);
    exit('Artigo não encontrado.');
}

$content = json_decode((string)($article['content_json'] ?? '[]'), true);
$faq = json_decode((string)($article['faq_json'] ?? '[]'), true);
$content = is_array($content) ? $content : [];
$faq = is_array($faq) ? $faq : [];
$image = bp_safe_url((string)($article['image_url'] ?? ''));
?>
<!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="robots" content="noindex,nofollow,noarchive">
<title>Preview: <?= bp_esc((string)$article['title']) ?></title>
<style>
body{font-family:Arial,sans-serif;margin:0;background:#f4f5f7;color:#202124}.bar{position:sticky;top:0;background:#202124;color:#fff;padding:12px 18px;z-index:2}.bar a{color:#fff}.wrap{max-width:860px;margin:28px auto;background:#fff;padding:28px;border-radius:14px}.badge{display:inline-block;background:#fff3cd;color:#664d03;padding:6px 10px;border-radius:999px;font-size:13px}.hero{width:100%;max-height:430px;object-fit:cover;border-radius:12px;margin:18px 0}h1{font-size:38px;line-height:1.15}.meta{color:#5f6368}.faq{border-top:1px solid #ddd;padding-top:18px;margin-top:28px}.faq article{margin-bottom:16px}p{line-height:1.7}ul{line-height:1.7}
</style>
</head>
<body>
<div class="bar">PREVIEW ADMINISTRATIVO — não indexável · <a href="/admin/blog.php?id=<?= (int)$article['id'] ?>">voltar à edição</a></div>
<main class="wrap">
<span class="badge"><?= bp_esc((string)$article['status']) ?></span>
<h1><?= bp_esc((string)$article['title']) ?></h1>
<p class="meta"><?= bp_esc((string)($article['category'] ?? '')) ?> · <?= (int)($article['reading_time'] ?? 5) ?> min · <?= bp_esc((string)($article['author'] ?? 'Equipe ShopVivaliz')) ?></p>
<p><strong><?= bp_esc((string)($article['excerpt'] ?? '')) ?></strong></p>
<?php if ($image !== ''): ?><img class="hero" src="<?= bp_esc($image) ?>" alt="<?= bp_esc((string)($article['image_alt'] ?? '')) ?>"><?php endif; ?>
<?php foreach ($content as $section): if (!is_array($section)) continue; ?>
<section>
<?php if (!empty($section['heading'])): ?><h2><?= bp_esc((string)$section['heading']) ?></h2><?php endif; ?>
<?php foreach ((array)($section['paragraphs'] ?? []) as $paragraph): ?><p><?= nl2br(bp_esc((string)$paragraph)) ?></p><?php endforeach; ?>
<?php if (!empty($section['items']) && is_array($section['items'])): ?><ul><?php foreach ($section['items'] as $item): ?><li><?= bp_esc((string)$item) ?></li><?php endforeach; ?></ul><?php endif; ?>
</section>
<?php endforeach; ?>
<?php if ($faq !== []): ?><section class="faq"><h2>Perguntas frequentes</h2><?php foreach ($faq as $item): if (!is_array($item)) continue; ?><article><h3><?= bp_esc((string)($item['question'] ?? '')) ?></h3><p><?= nl2br(bp_esc((string)($item['answer'] ?? ''))) ?></p></article><?php endforeach; ?></section><?php endif; ?>
</main>
</body>
</html>

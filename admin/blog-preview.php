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

function bp_render_state(string $title, string $message, int $status = 200): never
{
    http_response_code($status);
    $safeTitle = bp_esc($title);
    $safeMessage = bp_esc($message);
    echo <<<HTML
<!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<meta name="robots" content="noindex,nofollow,noarchive">
<title>{$safeTitle} — ShopVivaliz Admin</title>
<style>
*{box-sizing:border-box}body{margin:0;font-family:Inter,Arial,sans-serif;background:#f4f6fb;color:#111827}.bar{background:#173b63;color:#fff;padding:14px 18px;font-weight:800}.wrap{width:min(760px,calc(100% - 28px));margin:48px auto}.card{background:#fff;border:1px solid #e5e9f0;border-radius:16px;padding:26px;box-shadow:0 8px 28px rgba(17,24,39,.08)}h1{margin:0 0 10px;font-size:clamp(26px,6vw,38px)}p{line-height:1.6;color:#4b5563}.actions{display:flex;gap:10px;flex-wrap:wrap;margin-top:20px}.btn{display:inline-flex;padding:11px 15px;border-radius:9px;text-decoration:none;font-weight:800;background:#173b63;color:#fff}.btn.secondary{background:#eef2f7;color:#173b63}@media(max-width:520px){.wrap{margin:24px auto}.card{padding:20px}}
</style>
</head>
<body>
<div class="bar">ShopVivaliz · Prévia do blog</div>
<main class="wrap"><section class="card"><h1>{$safeTitle}</h1><p>{$safeMessage}</p><div class="actions"><a class="btn" href="/admin/blog.php">Abrir artigos do blog</a><a class="btn secondary" href="/admin/">Voltar ao Admin</a></div></section></main>
</body>
</html>
HTML;
    exit;
}

$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT) ?: 0;
if ($id <= 0) {
    bp_render_state('Selecione um artigo para visualizar', 'A prévia precisa de um artigo. Abra o Blog, escolha o conteúdo desejado e use a opção de pré-visualização.');
}

$db = Database::getInstance()->getConnection();
$stmt = $db->prepare('SELECT * FROM blog_articles WHERE id = ? LIMIT 1');
if (!$stmt) {
    bp_render_state('Não foi possível abrir a prévia', 'O banco não conseguiu preparar a consulta do artigo. Tente novamente pelo Blog.', 500);
}
$stmt->bind_param('i', $id);
$stmt->execute();
$article = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$article) {
    bp_render_state('Artigo não encontrado', 'Esse artigo não existe mais ou não está disponível para pré-visualização.', 404);
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
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<meta name="robots" content="noindex,nofollow,noarchive">
<title>Preview: <?= bp_esc((string)$article['title']) ?></title>
<style>
*{box-sizing:border-box}body{font-family:Arial,sans-serif;margin:0;background:#f4f5f7;color:#202124}.bar{position:sticky;top:0;background:#202124;color:#fff;padding:12px 18px;z-index:2}.bar a{color:#fff}.wrap{width:min(860px,calc(100% - 28px));margin:28px auto;background:#fff;padding:28px;border-radius:14px}.badge{display:inline-block;background:#fff3cd;color:#664d03;padding:6px 10px;border-radius:999px;font-size:13px}.hero{width:100%;max-height:430px;object-fit:cover;border-radius:12px;margin:18px 0}h1{font-size:clamp(30px,7vw,38px);line-height:1.15;overflow-wrap:anywhere}.meta{color:#5f6368}.faq{border-top:1px solid #ddd;padding-top:18px;margin-top:28px}.faq article{margin-bottom:16px}p,li{line-height:1.7;overflow-wrap:anywhere}ul{line-height:1.7}@media(max-width:560px){.wrap{margin:14px auto;padding:18px}.bar{font-size:13px}}
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

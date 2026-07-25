<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/admin-guard.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/../config/database.php';

function ab_esc(string $value): string { return htmlspecialchars($value, ENT_QUOTES, 'UTF-8'); }
function ab_slug(string $value): string {
    $value = mb_strtolower(trim($value), 'UTF-8');
    $value = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value) ?: $value;
    return trim(preg_replace('/[^a-z0-9]+/', '-', $value) ?? '', '-');
}

$db = Database::getInstance()->getConnection();
$error = '';
$success = '';
$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!sv_csrf_valid('admin-blog', $_POST['csrf_token'] ?? '')) {
        $error = 'Sessão expirada. Recarregue a página.';
    } else {
        $title = trim((string)($_POST['title'] ?? ''));
        $slug = ab_slug((string)($_POST['slug'] ?? $title));
        $status = (string)($_POST['status'] ?? 'draft');
        $allowed = ['draft', 'review', 'scheduled', 'published', 'archived'];
        $status = in_array($status, $allowed, true) ? $status : 'draft';
        $content = json_decode((string)($_POST['content_json'] ?? '[]'), true);
        $faq = json_decode((string)($_POST['faq_json'] ?? '[]'), true);
        $keywords = array_values(array_filter(array_map('trim', explode(',', (string)($_POST['keywords'] ?? '')))));
        if ($title === '' || $slug === '') {
            $error = 'Título e slug são obrigatórios.';
        } elseif (!is_array($content) || !is_array($faq)) {
            $error = 'Conteúdo ou FAQ em JSON inválido.';
        } else {
            $scheduledAt = trim((string)($_POST['scheduled_at'] ?? ''));
            $publishedAt = $status === 'published' ? gmdate('Y-m-d H:i:s') : null;
            $sql = $id > 0
                ? 'UPDATE blog_articles SET slug=?,title=?,excerpt=?,category=?,image_url=?,image_alt=?,content_json=?,faq_json=?,keywords_json=?,meta_title=?,meta_description=?,related_products_url=?,author=?,status=?,featured=?,reading_time=?,scheduled_at=?,published_at=COALESCE(?,published_at) WHERE id=?'
                : 'INSERT INTO blog_articles (slug,title,excerpt,category,image_url,image_alt,content_json,faq_json,keywords_json,meta_title,meta_description,related_products_url,author,status,featured,reading_time,scheduled_at,published_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)';
            $stmt = $db->prepare($sql);
            if (!$stmt) {
                $error = 'Não foi possível preparar a gravação.';
            } else {
                $excerpt = trim((string)($_POST['excerpt'] ?? ''));
                $category = trim((string)($_POST['category'] ?? ''));
                $image = trim((string)($_POST['image_url'] ?? ''));
                $imageAlt = trim((string)($_POST['image_alt'] ?? ''));
                $contentJson = json_encode($content, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                $faqJson = json_encode($faq, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                $keywordsJson = json_encode($keywords, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                $metaTitle = trim((string)($_POST['meta_title'] ?? $title));
                $metaDescription = trim((string)($_POST['meta_description'] ?? $excerpt));
                $related = trim((string)($_POST['related_products_url'] ?? '/catalogo'));
                $author = trim((string)($_POST['author'] ?? 'Equipe ShopVivaliz'));
                $featured = isset($_POST['featured']) ? 1 : 0;
                $readingTime = max(1, (int)($_POST['reading_time'] ?? 5));
                $scheduledValue = $scheduledAt !== '' ? str_replace('T', ' ', $scheduledAt) . ':00' : null;
                if ($id > 0) {
                    $stmt->bind_param('ssssssssssssssiiissi', $slug,$title,$excerpt,$category,$image,$imageAlt,$contentJson,$faqJson,$keywordsJson,$metaTitle,$metaDescription,$related,$author,$status,$featured,$readingTime,$scheduledValue,$publishedAt,$id);
                } else {
                    $stmt->bind_param('ssssssssssssssiiss', $slug,$title,$excerpt,$category,$image,$imageAlt,$contentJson,$faqJson,$keywordsJson,$metaTitle,$metaDescription,$related,$author,$status,$featured,$readingTime,$scheduledValue,$publishedAt);
                }
                if ($stmt->execute()) {
                    $success = 'Artigo salvo com sucesso.';
                    $id = $id > 0 ? $id : (int)$db->insert_id;
                } else {
                    $error = $stmt->errno === 1062 ? 'Já existe um artigo com esse slug.' : 'Falha ao salvar o artigo.';
                }
                $stmt->close();
            }
        }
    }
}

$article = null;
if ($id > 0) {
    $stmt = $db->prepare('SELECT * FROM blog_articles WHERE id=? LIMIT 1');
    $stmt->bind_param('i', $id);
    $stmt->execute();
    $article = $stmt->get_result()->fetch_assoc() ?: null;
    $stmt->close();
}
$list = $db->query('SELECT id,slug,title,category,status,scheduled_at,published_at,updated_at FROM blog_articles ORDER BY updated_at DESC LIMIT 200');
?>
<!doctype html><html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Blog editorial | Admin</title>
<style>body{font-family:Arial,sans-serif;background:#f6f7f9;margin:0;color:#202124}.wrap{max-width:1180px;margin:32px auto;padding:0 18px}.card{background:#fff;border:1px solid #ddd;border-radius:14px;padding:20px;margin-bottom:20px}label{display:block;font-weight:700;margin-top:12px}input,textarea,select{width:100%;box-sizing:border-box;padding:10px;margin-top:5px;border:1px solid #bbb;border-radius:8px}textarea{min-height:110px;font-family:monospace}.grid{display:grid;grid-template-columns:1fr 1fr;gap:14px}.actions{margin-top:18px}button,.btn{display:inline-block;background:#111;color:#fff;padding:11px 16px;border:0;border-radius:8px;text-decoration:none;cursor:pointer}table{width:100%;border-collapse:collapse}th,td{text-align:left;padding:10px;border-bottom:1px solid #eee}.msg{padding:12px;border-radius:8px}.ok{background:#e8f5e9}.err{background:#ffebee}@media(max-width:760px){.grid{grid-template-columns:1fr}table{font-size:13px}}</style></head><body><main class="wrap">
<h1>Gestão editorial do blog</h1>
<?php if ($success): ?><p class="msg ok"><?= ab_esc($success) ?></p><?php endif; ?>
<?php if ($error): ?><p class="msg err"><?= ab_esc($error) ?></p><?php endif; ?>
<section class="card"><h2><?= $article ? 'Editar artigo' : 'Novo artigo' ?></h2><form method="post">
<?= sv_csrf_input('admin-blog') ?><input type="hidden" name="id" value="<?= (int)($article['id'] ?? 0) ?>">
<div class="grid"><div><label>Título<input name="title" required value="<?= ab_esc((string)($article['title'] ?? '')) ?>"></label></div><div><label>Slug<input name="slug" value="<?= ab_esc((string)($article['slug'] ?? '')) ?>"></label></div></div>
<label>Resumo<textarea name="excerpt"><?= ab_esc((string)($article['excerpt'] ?? '')) ?></textarea></label>
<div class="grid"><label>Categoria<input name="category" value="<?= ab_esc((string)($article['category'] ?? '')) ?>"></label><label>Status<select name="status"><?php foreach(['draft'=>'Rascunho','review'=>'Revisão','scheduled'=>'Agendado','published'=>'Publicado','archived'=>'Arquivado'] as $v=>$n): ?><option value="<?= $v ?>" <?= (($article['status'] ?? 'draft')===$v)?'selected':'' ?>><?= $n ?></option><?php endforeach; ?></select></label></div>
<div class="grid"><label>Imagem<input name="image_url" value="<?= ab_esc((string)($article['image_url'] ?? '')) ?>"></label><label>Texto alternativo<input name="image_alt" value="<?= ab_esc((string)($article['image_alt'] ?? '')) ?>"></label></div>
<label>Conteúdo estruturado em JSON<textarea name="content_json" required><?= ab_esc((string)($article['content_json'] ?? '[{"heading":"Introdução","paragraphs":["Texto do artigo."]}]')) ?></textarea></label>
<label>FAQ em JSON<textarea name="faq_json"><?= ab_esc((string)($article['faq_json'] ?? '[]')) ?></textarea></label>
<label>Palavras-chave separadas por vírgula<input name="keywords" value="<?= ab_esc(implode(', ', json_decode((string)($article['keywords_json'] ?? '[]'), true) ?: [])) ?>"></label>
<div class="grid"><label>Meta title<input name="meta_title" value="<?= ab_esc((string)($article['meta_title'] ?? '')) ?>"></label><label>Meta description<input name="meta_description" value="<?= ab_esc((string)($article['meta_description'] ?? '')) ?>"></label></div>
<div class="grid"><label>Produtos relacionados<input name="related_products_url" value="<?= ab_esc((string)($article['related_products_url'] ?? '/catalogo')) ?>"></label><label>Autor<input name="author" value="<?= ab_esc((string)($article['author'] ?? 'Equipe ShopVivaliz')) ?>"></label></div>
<div class="grid"><label>Tempo de leitura<input type="number" min="1" name="reading_time" value="<?= (int)($article['reading_time'] ?? 5) ?>"></label><label>Agendar para<input type="datetime-local" name="scheduled_at" value="<?= !empty($article['scheduled_at']) ? ab_esc(str_replace(' ', 'T', substr((string)$article['scheduled_at'],0,16))) : '' ?>"></label></div>
<label><input style="width:auto" type="checkbox" name="featured" <?= !empty($article['featured'])?'checked':'' ?>> Artigo em destaque</label>
<div class="actions"><button type="submit">Salvar artigo</button> <?php if ($article): ?><a class="btn" href="/admin/blog-preview.php?id=<?= (int)$article['id'] ?>" target="_blank" rel="noopener">Preview seguro</a><?php if (($article['status'] ?? '') === 'published'): ?> <a class="btn" href="/blog/<?= rawurlencode((string)$article['slug']) ?>" target="_blank" rel="noopener">Abrir página pública</a><?php endif; ?><?php endif; ?> <a class="btn" href="/admin/blog.php">Novo</a></div>
</form></section>
<section class="card"><h2>Artigos</h2><div style="overflow:auto"><table><thead><tr><th>Título</th><th>Categoria</th><th>Status</th><th>Agendamento</th><th>Atualização</th><th></th></tr></thead><tbody><?php while($row=$list->fetch_assoc()): ?><tr><td><?= ab_esc((string)$row['title']) ?><br><small><?= ab_esc((string)$row['slug']) ?></small></td><td><?= ab_esc((string)$row['category']) ?></td><td><?= ab_esc((string)$row['status']) ?></td><td><?= ab_esc((string)($row['scheduled_at'] ?? '')) ?></td><td><?= ab_esc((string)$row['updated_at']) ?></td><td><a href="?id=<?= (int)$row['id'] ?>">Editar</a> · <a href="/admin/blog-preview.php?id=<?= (int)$row['id'] ?>" target="_blank" rel="noopener">Preview</a></td></tr><?php endwhile; ?></tbody></table></div></section>
</main></body></html>

<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/admin-guard.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/blog-comment-repository.php';

function abc_esc(string $value): string { return htmlspecialchars($value, ENT_QUOTES, 'UTF-8'); }

$repository = BlogCommentRepository::fromApplicationDatabase();
$status = (string)($_GET['status'] ?? 'pending');
$allowedStatuses = ['pending','published','rejected','spam','hidden'];
$status = in_array($status, $allowedStatuses, true) ? $status : 'pending';
$message = '';
$error = '';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!sv_csrf_valid('admin-blog-comments', $_POST['csrf_token'] ?? '')) {
        $error = 'Sessão expirada. Recarregue a página.';
    } else {
        $commentId = (int)($_POST['comment_id'] ?? 0);
        $action = (string)($_POST['action'] ?? '');
        $map = ['publish' => 'published', 'reject' => 'rejected', 'spam' => 'spam', 'hide' => 'hidden', 'pending' => 'pending'];
        if ($commentId <= 0 || !isset($map[$action])) {
            $error = 'Ação inválida.';
        } elseif (!$repository->updateStatus($commentId, $map[$action], trim((string)($_POST['reason'] ?? '')) ?: null)) {
            $error = 'Não foi possível atualizar o comentário.';
        } else {
            $repository->audit($commentId, 'admin', (string)($_SESSION['admin_id'] ?? $_SESSION['user_id'] ?? 'admin'), 'moderation_' . $map[$action], ['reason' => trim((string)($_POST['reason'] ?? ''))]);
            $replyText = trim((string)($_POST['reply'] ?? ''));
            if ($replyText !== '') {
                $repository->addReply($commentId, 'admin', 'Equipe ShopVivaliz', strip_tags($replyText), false, null, null);
                $repository->audit($commentId, 'admin', (string)($_SESSION['admin_id'] ?? $_SESSION['user_id'] ?? 'admin'), 'admin_reply_created');
            }
            $message = 'Comentário atualizado.';
        }
    }
}

$rows = $repository->listForModeration($status, 200);
?>
<!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Comentários do blog | Admin</title>
<style>
body{font-family:Arial,sans-serif;background:#f6f7f9;margin:0;color:#202124}.wrap{max-width:1180px;margin:32px auto;padding:0 18px}.toolbar,.card{background:#fff;border:1px solid #ddd;border-radius:14px;padding:18px;margin-bottom:18px}.tabs{display:flex;gap:8px;flex-wrap:wrap}.tabs a,.btn,button{display:inline-block;padding:9px 12px;border-radius:8px;border:0;text-decoration:none;cursor:pointer;background:#173B63;color:#fff}.tabs a.active{background:#059669}.comment{border-top:1px solid #eee;padding:18px 0}.comment:first-child{border-top:0}.meta{display:flex;gap:10px;flex-wrap:wrap;color:#666;font-size:13px}.actions{display:flex;gap:8px;flex-wrap:wrap;margin-top:12px}textarea,input{width:100%;box-sizing:border-box;padding:10px;border:1px solid #bbb;border-radius:8px;margin-top:8px}.ok{background:#e8f5e9;padding:12px;border-radius:8px}.err{background:#ffebee;padding:12px;border-radius:8px}.muted{color:#666}.danger{background:#b91c1c}.warning{background:#b45309}.secondary{background:#4b5563}
</style>
</head>
<body><main class="wrap">
<h1>Moderação de comentários</h1>
<p><a href="/admin/blog.php">← Voltar para gestão editorial</a></p>
<?php if ($message): ?><p class="ok"><?= abc_esc($message) ?></p><?php endif; ?>
<?php if ($error): ?><p class="err"><?= abc_esc($error) ?></p><?php endif; ?>
<section class="toolbar"><div class="tabs">
<?php foreach ($allowedStatuses as $item): ?><a class="<?= $item === $status ? 'active' : '' ?>" href="?status=<?= abc_esc($item) ?>"><?= abc_esc(ucfirst($item)) ?></a><?php endforeach; ?>
</div></section>
<section class="card">
<?php if ($rows === []): ?><p class="muted">Nenhum comentário neste estado.</p><?php endif; ?>
<?php foreach ($rows as $row): ?>
<article class="comment">
<h2><?= abc_esc((string)$row['name']) ?></h2>
<div class="meta"><span>Artigo: <?= abc_esc((string)$row['article_slug']) ?></span><span>Status: <?= abc_esc((string)$row['status']) ?></span><span><?= abc_esc((string)$row['created_at']) ?></span></div>
<p><?= nl2br(abc_esc((string)$row['message'])) ?></p>
<?php if (!empty($row['moderation_reason'])): ?><p class="muted">Motivo: <?= abc_esc((string)$row['moderation_reason']) ?></p><?php endif; ?>
<form method="post">
<?= sv_csrf_input('admin-blog-comments') ?>
<input type="hidden" name="comment_id" value="<?= (int)$row['id'] ?>">
<label>Motivo da moderação<input name="reason" maxlength="255"></label>
<label>Resposta manual opcional<textarea name="reply" rows="3" maxlength="2000"></textarea></label>
<div class="actions">
<button name="action" value="publish">Publicar</button>
<button class="secondary" name="action" value="pending">Pendente</button>
<button class="warning" name="action" value="hide">Ocultar</button>
<button class="danger" name="action" value="reject">Rejeitar</button>
<button class="danger" name="action" value="spam">Marcar spam</button>
</div>
</form>
</article>
<?php endforeach; ?>
</section>
</main></body></html>
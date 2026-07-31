<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/admin-guard.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/testimonials-repository.php';
function e(string $v): string { return htmlspecialchars($v, ENT_QUOTES, 'UTF-8'); }
$repo = new TestimonialsRepository();
$allowed = ['pending','approved','rejected','hidden'];
$status = (string)($_GET['status'] ?? 'pending');
if (!in_array($status, $allowed, true)) $status = 'pending';
$notice = '';
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && sv_csrf_valid('admin-testimonials', $_POST['csrf_token'] ?? '')) {
    $map = ['approve'=>'approved','reject'=>'rejected','hide'=>'hidden','pending'=>'pending'];
    $action = (string)($_POST['action'] ?? '');
    if (isset($map[$action]) && $repo->moderate((string)($_POST['id'] ?? ''), $map[$action], (string)($_POST['reason'] ?? ''))) $notice = 'Avaliação atualizada.';
}
$rows = $repo->byStatus($status);
?>
<!doctype html><html lang="pt-BR"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Avaliações | Admin</title><style>body{font-family:Arial;background:#f5f7fa;color:#1f2937}.wrap{max-width:1000px;margin:30px auto;padding:16px}.card,.tabs{background:#fff;border:1px solid #d8dee8;border-radius:12px;padding:16px;margin-bottom:14px}.tabs a,button{display:inline-block;padding:9px 12px;margin:3px;border:0;border-radius:8px;background:#173b63;color:#fff;text-decoration:none;cursor:pointer}.tabs a.active{background:#059669}.danger{background:#b91c1c}.muted{background:#6b7280}.warn{background:#b45309}input{width:100%;box-sizing:border-box;padding:9px;margin:10px 0}.meta{color:#64748b;font-size:13px}.stars{color:#f59e0b}</style></head><body><main class="wrap"><h1>Moderação de avaliações</h1><p><a href="/admin/">Voltar ao admin</a></p><?php if($notice):?><p><?=e($notice)?></p><?php endif;?><nav class="tabs"><?php foreach($allowed as $s):?><a class="<?=$s===$status?'active':''?>" href="?status=<?=e($s)?>"><?=e(ucfirst($s))?></a><?php endforeach;?></nav><?php if(!$rows):?><div class="card">Nenhuma avaliação.</div><?php endif;?><?php foreach($rows as $row):?><article class="card"><h2><?=e((string)$row['name'])?></h2><p class="meta"><span class="stars"><?=str_repeat('★',(int)$row['rating'])?></span> · <?=e((string)($row['city']??''))?> · <?=e((string)($row['created_at']??''))?></p><p><?=nl2br(e((string)$row['message']))?></p><form method="post"><?=sv_csrf_input('admin-testimonials')?><input type="hidden" name="id" value="<?=e((string)$row['id'])?>"><input name="reason" maxlength="255" placeholder="Motivo opcional"><button name="action" value="approve">Aprovar</button><button class="muted" name="action" value="pending">Pendente</button><button class="warn" name="action" value="hide">Ocultar</button><button class="danger" name="action" value="reject">Rejeitar</button></form></article><?php endforeach;?></main></body></html>

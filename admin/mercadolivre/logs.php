<?php
declare(strict_types=1);
$logFile = dirname(__DIR__, 2) . '/storage/logs/ml-webhook.log';
$lines   = [];
$error   = '';
if (is_file($logFile)) {
    $raw   = file($logFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
    $lines = array_reverse(array_slice($raw, -500)); // Últimas 500 linhas, mais recente primeiro
} else {
    $error = 'Arquivo de log não encontrado: storage/logs/ml-webhook.log';
}
$page    = max(1, (int)($_GET['page'] ?? 1));
$perPage = 50;
$total   = count($lines);
$pages   = max(1, (int)ceil($total / $perPage));
$page    = min($page, $pages);
$slice   = array_slice($lines, ($page - 1) * $perPage, $perPage);
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Logs ML — ShopVivaliz</title>
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:system-ui,sans-serif;background:#f5f5f5;color:#333}
.topbar{background:#fff059;padding:14px 24px;display:flex;align-items:center;gap:12px;border-bottom:2px solid #e0b800}
.topbar h1{font-size:1.2rem;font-weight:700;color:#333}
.topbar .logo{font-size:1.6rem}
nav{background:#333;padding:0 24px;display:flex;gap:4px}
nav a{color:#fff;text-decoration:none;padding:10px 16px;font-size:.875rem;display:block;opacity:.8;transition:.15s}
nav a:hover,nav a.active{background:#555;opacity:1}
.container{max-width:1100px;margin:0 auto;padding:24px}
.toolbar{display:flex;align-items:center;gap:10px;margin-bottom:16px;flex-wrap:wrap}
.toolbar a,.btn{display:inline-block;padding:7px 14px;border-radius:6px;font-size:.85rem;font-weight:600;text-decoration:none;cursor:pointer;border:none;background:#333;color:#fff;transition:.15s}
.toolbar a:hover,.btn:hover{background:#555}
.btn.yellow{background:#fff059;color:#333}.btn.yellow:hover{background:#ffe000}
.meta{font-size:.82rem;color:#888;margin-bottom:12px}
.log-block{background:#1e1e1e;color:#d4d4d4;border-radius:8px;padding:16px;font-family:monospace;font-size:.78rem;line-height:1.7;overflow-x:auto}
.log-line{border-bottom:1px solid #2a2a2a;padding:3px 0}
.log-line:last-child{border-bottom:none}
.log-ts{color:#6a9955}
.log-topic{color:#9cdcfe}
.log-resource{color:#ce9178}
.log-user{color:#dcdcaa}
.empty{padding:40px;text-align:center;color:#888}
.error-box{background:#fee2e2;color:#991b1b;padding:12px 16px;border-radius:8px;margin-bottom:16px}
.pagination{display:flex;gap:8px;margin-top:16px;align-items:center;flex-wrap:wrap}
.pagination a{padding:5px 12px;border-radius:4px;background:#fff;border:1px solid #ddd;font-size:.8rem;text-decoration:none;color:#333;transition:.15s}
.pagination a:hover{background:#f0f0f0}
.pagination a.current{background:#333;color:#fff;border-color:#333}
.pagination span{font-size:.82rem;color:#888}
</style>
</head>
<body>
<div class="topbar">
  <span class="logo">🛒</span>
  <h1>Mercado Livre — ShopVivaliz</h1>
  <div style="margin-left:auto;font-size:.8rem;color:#666">Admin</div>
</div>
<nav>
  <a href="index.php">Dashboard</a>
  <a href="produtos.php">Produtos</a>
  <a href="pedidos.php">Pedidos</a>
  <a href="configuracoes.php">Configurações</a>
  <a href="logs.php" class="active">Logs</a>
  <a href="../../admin/">← Admin</a>
</nav>
<div class="container">
  <div class="toolbar">
    <a href="logs.php">Atualizar</a>
    <span style="margin-left:auto;font-size:.82rem;color:#888"><?= $total ?> linha(s) · Mostrando mais recentes primeiro</span>
  </div>

  <?php if ($error): ?>
    <div class="error-box"><?= htmlspecialchars($error) ?></div>
    <p style="font-size:.85rem;color:#666">Nenhum webhook recebido ainda. Os eventos aparecerão aqui quando o ML enviar notificações para <code>/api/ml/webhook.php</code>.</p>
  <?php elseif (empty($slice)): ?>
    <div class="empty">Nenhuma entrada de log encontrada.</div>
  <?php else: ?>
    <p class="meta">Arquivo: <code><?= htmlspecialchars($logFile) ?></code></p>
    <div class="log-block">
      <?php foreach ($slice as $line):
        // Format: 2024-01-15T12:00:00+00:00 | topic=orders_v2 resource=/orders/123 user_id=456
        $parts = explode(' | ', $line, 2);
        $ts    = htmlspecialchars($parts[0] ?? '');
        $rest  = htmlspecialchars($parts[1] ?? $line);
        // Colorize fields
        $rest = preg_replace('/\btopic=(\S+)/', 'topic=<span class="log-topic">$1</span>', $rest);
        $rest = preg_replace('/\bresource=(\S+)/', 'resource=<span class="log-resource">$1</span>', $rest);
        $rest = preg_replace('/\buser_id=(\S+)/', 'user_id=<span class="log-user">$1</span>', $rest);
      ?>
      <div class="log-line"><span class="log-ts"><?= $ts ?></span><?= $parts[1] !== null ? ' | ' . $rest : '' ?></div>
      <?php endforeach; ?>
    </div>

    <?php if ($pages > 1): ?>
    <div class="pagination">
      <span>Página <?= $page ?> de <?= $pages ?></span>
      <?php if ($page > 1): ?>
        <a href="?page=1">« Primeira</a>
        <a href="?page=<?= $page - 1 ?>">← Anterior</a>
      <?php endif; ?>
      <?php for ($p = max(1, $page-2); $p <= min($pages, $page+2); $p++): ?>
        <a href="?page=<?= $p ?>" class="<?= $p === $page ? 'current' : '' ?>"><?= $p ?></a>
      <?php endfor; ?>
      <?php if ($page < $pages): ?>
        <a href="?page=<?= $page + 1 ?>">Próxima →</a>
        <a href="?page=<?= $pages ?>">Última »</a>
      <?php endif; ?>
    </div>
    <?php endif; ?>
  <?php endif; ?>
</div>
</body>
</html>

<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/api/ml/config.php';
ml_load_env();
$tokens    = ml_tokens();
$connected = !empty(ml_access_token());
$userId    = $tokens['user_id'] ?? getenv('ML_SELLER_ID') ?: '—';
$source    = getenv('ML_ACCESS_TOKEN') ? 'env:ML_ACCESS_TOKEN' : (empty($tokens) ? 'não encontrado' : 'data/ml-tokens.json');
$expiresAt = isset($tokens['expires_at']) ? date('d/m/Y H:i:s', (int)$tokens['expires_at']) : '—';
$hasRefresh = !empty($tokens['refresh_token']);
$hasClient  = !empty(getenv('ML_CLIENT_ID')) || !empty($tokens['client_id']);
$webhookUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http')
            . '://' . ($_SERVER['HTTP_HOST'] ?? 'seu-dominio.com')
            . '/api/ml/webhook.php';
$oauthUrl   = 'https://auth.mercadolivre.com.br/authorization'
            . '?response_type=code'
            . '&client_id=' . urlencode(getenv('ML_CLIENT_ID') ?: '[ML_CLIENT_ID]')
            . '&redirect_uri=' . urlencode(getenv('ML_REDIRECT_URI') ?: '[ML_REDIRECT_URI]');
$secrets = [
    'ML_CLIENT_ID'     => !empty(getenv('ML_CLIENT_ID')),
    'ML_CLIENT_SECRET' => !empty(getenv('ML_CLIENT_SECRET')),
    'ML_ACCESS_TOKEN'  => !empty(getenv('ML_ACCESS_TOKEN')),
    'ML_SELLER_ID'     => !empty(getenv('ML_SELLER_ID')),
    'ML_REDIRECT_URI'  => !empty(getenv('ML_REDIRECT_URI')),
];
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Configurações ML — ShopVivaliz</title>
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:system-ui,sans-serif;background:#f5f5f5;color:#333}
.topbar{background:#fff059;padding:14px 24px;display:flex;align-items:center;gap:12px;border-bottom:2px solid #e0b800}
.topbar h1{font-size:1.2rem;font-weight:700;color:#333}
.topbar .logo{font-size:1.6rem}
nav{background:#333;padding:0 24px;display:flex;gap:4px}
nav a{color:#fff;text-decoration:none;padding:10px 16px;font-size:.875rem;display:block;opacity:.8;transition:.15s}
nav a:hover,nav a.active{background:#555;opacity:1}
.container{max-width:900px;margin:0 auto;padding:24px}
.section{background:#fff;border-radius:8px;padding:20px 24px;box-shadow:0 1px 3px rgba(0,0,0,.1);margin-bottom:20px}
.section h2{font-size:1rem;font-weight:700;margin-bottom:16px;padding-bottom:10px;border-bottom:1px solid #f0f0f0}
dl.grid{display:grid;grid-template-columns:180px 1fr;gap:10px 16px;align-items:baseline}
dt{font-size:.8rem;color:#888;text-transform:uppercase;letter-spacing:.04em;padding-top:2px}
dd{font-size:.9rem;font-weight:500}
.dot{display:inline-block;width:10px;height:10px;border-radius:50%;margin-right:6px;flex-shrink:0}
.dot.on{background:#22c55e}.dot.off{background:#ef4444}
code.url{background:#f5f5f5;padding:4px 8px;border-radius:4px;font-size:.8rem;word-break:break-all;display:block;margin-top:4px}
.secret-row{display:flex;align-items:center;gap:8px;padding:6px 0;border-bottom:1px solid #f5f5f5}
.secret-row:last-child{border-bottom:none}
.secret-name{font-family:monospace;font-size:.85rem;flex:1}
.ok{color:#22c55e;font-weight:700}.missing{color:#ef4444;font-weight:700}
.btn{display:inline-block;padding:8px 16px;border-radius:6px;font-size:.85rem;font-weight:600;text-decoration:none;cursor:pointer;border:none;background:#333;color:#fff;transition:.15s}
.btn:hover{background:#555}
.btn.yellow{background:#fff059;color:#333}.btn.yellow:hover{background:#ffe000}
.btn.danger{background:#ef4444;color:#fff}.btn.danger:hover{background:#dc2626}
.actions{display:flex;gap:10px;flex-wrap:wrap;margin-top:16px}
#refreshResult{margin-top:10px;font-size:.85rem;display:none}
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
  <a href="configuracoes.php" class="active">Configurações</a>
  <a href="logs.php">Logs</a>
  <a href="../../admin/">← Admin</a>
</nav>
<div class="container">

  <!-- Status OAuth -->
  <div class="section">
    <h2>Status OAuth</h2>
    <dl class="grid">
      <dt>Conexão</dt>
      <dd><span class="dot <?= $connected ? 'on' : 'off' ?>"></span><?= $connected ? 'Conectado' : 'Desconectado' ?></dd>
      <dt>User / Seller ID</dt>
      <dd><?= htmlspecialchars((string)$userId) ?></dd>
      <dt>Token expira</dt>
      <dd><?= htmlspecialchars($expiresAt) ?></dd>
      <dt>Fonte do token</dt>
      <dd><code><?= htmlspecialchars($source) ?></code></dd>
      <dt>Refresh token</dt>
      <dd><?= $hasRefresh ? '<span class="ok">✔ Presente</span>' : '<span class="missing">✘ Ausente</span>' ?></dd>
      <dt>Client ID</dt>
      <dd><?= $hasClient ? '<span class="ok">✔ Configurado</span>' : '<span class="missing">✘ Não configurado</span>' ?></dd>
    </dl>
    <div class="actions">
      <?php if ($connected && $hasRefresh): ?>
        <button class="btn yellow" onclick="refreshToken()">Renovar token agora</button>
      <?php endif; ?>
      <?php if (!empty(getenv('ML_CLIENT_ID')) && !empty(getenv('ML_REDIRECT_URI'))): ?>
        <a href="<?= htmlspecialchars($oauthUrl) ?>" class="btn" target="_blank">Reconectar via OAuth</a>
      <?php else: ?>
        <span style="font-size:.82rem;color:#888">Configure ML_CLIENT_ID e ML_REDIRECT_URI para habilitar reconexão OAuth.</span>
      <?php endif; ?>
    </div>
    <div id="refreshResult"></div>
  </div>

  <!-- Webhook -->
  <div class="section">
    <h2>Webhook</h2>
    <p style="font-size:.875rem;color:#555;margin-bottom:8px">Configure esta URL no painel do Mercado Livre em <strong>Integrações → Notificações</strong>:</p>
    <code class="url"><?= htmlspecialchars($webhookUrl) ?></code>
    <p style="font-size:.78rem;color:#888;margin-top:8px">Tópicos sugeridos: orders_v2, payments, questions, claims, messages, items</p>
  </div>

  <!-- Secrets -->
  <div class="section">
    <h2>Variáveis de Ambiente / Secrets</h2>
    <p style="font-size:.82rem;color:#888;margin-bottom:12px">Configure estas variáveis no servidor (.env ou painel de hosting / GitHub Secrets):</p>
    <?php foreach ($secrets as $name => $set): ?>
    <div class="secret-row">
      <span class="secret-name"><?= htmlspecialchars($name) ?></span>
      <?= $set ? '<span class="ok">✔ Configurado</span>' : '<span class="missing">✘ Ausente</span>' ?>
    </div>
    <?php endforeach; ?>
    <div style="margin-top:12px;padding:12px;background:#fffbeb;border-radius:6px;font-size:.8rem;color:#854d0e">
      <strong>Nota de segurança:</strong> Os valores dos secrets nunca são exibidos nesta página. Apenas a presença é verificada.
    </div>
  </div>

  <!-- API Info -->
  <div class="section">
    <h2>Informações da API</h2>
    <dl class="grid">
      <dt>Base URL</dt>
      <dd><code>https://api.mercadolibre.com</code></dd>
      <dt>OAuth URL</dt>
      <dd><code>https://api.mercadolibre.com/oauth/token</code></dd>
      <dt>Docs</dt>
      <dd><a href="https://developers.mercadolivre.com.br" target="_blank" style="color:#333">developers.mercadolivre.com.br</a></dd>
    </dl>
  </div>

</div>
<script>
function refreshToken() {
  const el = document.getElementById('refreshResult');
  el.style.display = 'block';
  el.style.color = '#666';
  el.textContent = 'Renovando token...';
  fetch('../../api/ml/refresh-token.php')
    .then(r => r.json())
    .then(d => {
      el.style.color = d.ok ? '#166534' : '#991b1b';
      el.textContent = d.ok ? '✔ Token renovado em ' + (d.renewed_at || '') : '✘ Erro: ' + (d.error || 'desconhecido');
    })
    .catch(e => { el.style.color = '#991b1b'; el.textContent = '✘ Erro de rede: ' + e.message; });
}
</script>
</body>
</html>

<?php
declare(strict_types=1);
require_once dirname(__DIR__, 2) . '/api/ml/config.php';
ml_load_env();
$tokens    = ml_tokens();
$connected = !empty(ml_access_token());
$userId    = $tokens['user_id'] ?? getenv('ML_SELLER_ID') ?: '—';
$expiresAt = isset($tokens['expires_at']) ? date('d/m/Y H:i', (int)$tokens['expires_at']) : '—';
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Mercado Livre — ShopVivaliz</title>
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
.status-bar{display:flex;align-items:center;gap:10px;background:#fff;border-radius:8px;padding:14px 20px;margin-bottom:24px;box-shadow:0 1px 3px rgba(0,0,0,.1)}
.dot{width:12px;height:12px;border-radius:50%;flex-shrink:0}
.dot.on{background:#22c55e}.dot.off{background:#ef4444}
.cards{display:grid;grid-template-columns:repeat(auto-fill,minmax(220px,1fr));gap:16px;margin-bottom:24px}
.card{background:#fff;border-radius:8px;padding:20px;box-shadow:0 1px 3px rgba(0,0,0,.1);text-decoration:none;color:#333;transition:.15s;display:block}
.card:hover{box-shadow:0 4px 12px rgba(0,0,0,.15);transform:translateY(-2px)}
.card .icon{font-size:2rem;margin-bottom:8px}
.card h3{font-size:1rem;font-weight:600;margin-bottom:4px}
.card p{font-size:.8rem;color:#666}
.info-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:16px}
.info-box{background:#fff;border-radius:8px;padding:16px;box-shadow:0 1px 3px rgba(0,0,0,.1)}
.info-box dt{font-size:.75rem;color:#888;text-transform:uppercase;letter-spacing:.05em;margin-bottom:4px}
.info-box dd{font-size:.95rem;font-weight:600}
.btn{display:inline-block;padding:8px 16px;border-radius:6px;font-size:.85rem;font-weight:600;text-decoration:none;cursor:pointer;border:none;background:#333;color:#fff;transition:.15s}
.btn:hover{background:#555}
.btn.yellow{background:#fff059;color:#333}.btn.yellow:hover{background:#ffe000}
.btn.danger{background:#ef4444;color:#fff}.btn.danger:hover{background:#dc2626}
</style>
</head>
<body>
<div class="topbar">
  <span class="logo">🛒</span>
  <h1>Mercado Livre — ShopVivaliz</h1>
  <div style="margin-left:auto;font-size:.8rem;color:#666">Admin</div>
</div>
<nav>
  <a href="index.php" class="active">Dashboard</a>
  <a href="produtos.php">Produtos</a>
  <a href="pedidos.php">Pedidos</a>
  <a href="configuracoes.php">Configurações</a>
  <a href="logs.php">Logs</a>
  <a href="../../admin/">← Admin</a>
</nav>
<div class="container">

  <div class="status-bar">
    <div class="dot <?= $connected ? 'on' : 'off' ?>"></div>
    <strong><?= $connected ? 'Conectado' : 'Desconectado' ?></strong>
    <?php if ($connected): ?>
      &nbsp;· User ID: <code><?= htmlspecialchars((string)$userId) ?></code>
      &nbsp;· Token expira: <?= htmlspecialchars($expiresAt) ?>
      <a href="../../api/ml/refresh-token.php" class="btn" style="margin-left:auto" onclick="return confirm('Renovar token agora?')">Renovar token</a>
    <?php else: ?>
      <span style="margin-left:auto;color:#ef4444">Configure ML_ACCESS_TOKEN ou ml-tokens.json no servidor.</span>
    <?php endif; ?>
  </div>

  <div class="cards">
    <a class="card" href="produtos.php">
      <div class="icon">📦</div>
      <h3>Produtos</h3>
      <p>Ver e gerenciar anúncios ativos</p>
    </a>
    <a class="card" href="pedidos.php">
      <div class="icon">🧾</div>
      <h3>Pedidos</h3>
      <p>Acompanhar pedidos recebidos</p>
    </a>
    <a class="card" href="configuracoes.php">
      <div class="icon">⚙️</div>
      <h3>Configurações</h3>
      <p>Status OAuth, webhook e secrets</p>
    </a>
    <a class="card" href="logs.php">
      <div class="icon">📋</div>
      <h3>Logs</h3>
      <p>Eventos e webhooks recebidos</p>
    </a>
  </div>

  <dl class="info-grid">
    <div class="info-box"><dt>Status</dt><dd><?= $connected ? '✅ Conectado' : '❌ Desconectado' ?></dd></div>
    <div class="info-box"><dt>User ID / Seller ID</dt><dd><?= htmlspecialchars((string)$userId) ?></dd></div>
    <div class="info-box"><dt>Token expira</dt><dd><?= htmlspecialchars($expiresAt) ?></dd></div>
    <div class="info-box"><dt>API Base</dt><dd>api.mercadolibre.com</dd></div>
  </dl>

</div>
</body>
</html>

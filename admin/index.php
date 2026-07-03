<?php declare(strict_types=1); ?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Admin — ShopVivaliz</title>
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:system-ui,sans-serif;background:#f5f5f5;color:#333;min-height:100vh}
.topbar{background:#333;padding:14px 24px;display:flex;align-items:center;gap:12px;border-bottom:2px solid #111}
.topbar h1{font-size:1.2rem;font-weight:700;color:#fff}
.topbar .logo{font-size:1.6rem}
.topbar .sub{font-size:.8rem;color:#aaa;margin-left:auto}
.container{max-width:1100px;margin:0 auto;padding:32px 24px}
.section-title{font-size:.75rem;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:#888;margin-bottom:12px;margin-top:28px}
.section-title:first-child{margin-top:0}
.cards{display:grid;grid-template-columns:repeat(auto-fill,minmax(200px,1fr));gap:16px;margin-bottom:8px}
.card{background:#fff;border-radius:10px;padding:22px;box-shadow:0 1px 3px rgba(0,0,0,.1);text-decoration:none;color:#333;transition:.15s;display:block;border:2px solid transparent}
.card:hover{box-shadow:0 4px 14px rgba(0,0,0,.15);transform:translateY(-2px)}
.card.ml{border-color:#fff059}
.card .icon{font-size:2rem;margin-bottom:10px}
.card h3{font-size:.95rem;font-weight:700;margin-bottom:4px}
.card p{font-size:.78rem;color:#777;line-height:1.4}
.card .badge{display:inline-block;margin-top:8px;padding:2px 8px;border-radius:10px;font-size:.68rem;font-weight:600;background:#f0f0f0;color:#555}
.card.ml .badge{background:#fff059;color:#555}
footer{text-align:center;font-size:.75rem;color:#aaa;padding:32px 0 16px}
</style>
</head>
<body>
<div class="topbar">
  <span class="logo">🏪</span>
  <h1>ShopVivaliz — Admin</h1>
  <span class="sub">Painel de Controle</span>
</div>
<div class="container">

  <p class="section-title">Integrações de Marketplace</p>
  <div class="cards">
    <a class="card ml" href="mercadolivre/">
      <div class="icon">🛒</div>
      <h3>Mercado Livre</h3>
      <p>Produtos, pedidos, OAuth e webhooks</p>
      <span class="badge">ML Panel</span>
    </a>
  </div>

  <p class="section-title">Ferramentas</p>
  <div class="cards">
    <a class="card" href="squad-chat.php">
      <div class="icon">💬</div>
      <h3>Squad Chat</h3>
      <p>Chat interno da equipe</p>
      <span class="badge">Interno</span>
    </a>
  </div>

</div>
<footer>ShopVivaliz Admin &mdash; <?= date('Y') ?></footer>
</body>
</html>

<?php declare(strict_types=1); ?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Produtos ML — ShopVivaliz</title>
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:system-ui,sans-serif;background:#f5f5f5;color:#333}
.topbar{background:#fff059;padding:14px 24px;display:flex;align-items:center;gap:12px;border-bottom:2px solid #e0b800}
.topbar h1{font-size:1.2rem;font-weight:700;color:#333}
.topbar .logo{font-size:1.6rem}
nav{background:#333;padding:0 24px;display:flex;gap:4px}
nav a{color:#fff;text-decoration:none;padding:10px 16px;font-size:.875rem;display:block;opacity:.8;transition:.15s}
nav a:hover,nav a.active{background:#555;opacity:1}
.container{max-width:1200px;margin:0 auto;padding:24px}
.toolbar{display:flex;align-items:center;gap:10px;margin-bottom:16px;flex-wrap:wrap}
.toolbar select,.toolbar input{padding:7px 12px;border:1px solid #ddd;border-radius:6px;font-size:.875rem;background:#fff}
.btn{display:inline-block;padding:8px 16px;border-radius:6px;font-size:.85rem;font-weight:600;text-decoration:none;cursor:pointer;border:none;background:#333;color:#fff;transition:.15s}
.btn:hover{background:#555}
.btn.yellow{background:#fff059;color:#333}.btn.yellow:hover{background:#ffe000}
.btn.sm{padding:5px 10px;font-size:.78rem}
table{width:100%;border-collapse:collapse;background:#fff;border-radius:8px;overflow:hidden;box-shadow:0 1px 3px rgba(0,0,0,.1)}
th{background:#f9f9f9;text-align:left;padding:10px 14px;font-size:.78rem;text-transform:uppercase;letter-spacing:.04em;color:#666;border-bottom:1px solid #eee}
td{padding:10px 14px;font-size:.875rem;border-bottom:1px solid #f0f0f0;vertical-align:middle}
tr:last-child td{border-bottom:none}
tr:hover td{background:#fafafa}
.thumb{width:44px;height:44px;object-fit:cover;border-radius:4px;border:1px solid #eee}
.thumb-placeholder{width:44px;height:44px;border-radius:4px;background:#f0f0f0;display:inline-flex;align-items:center;justify-content:center;font-size:1.2rem}
.badge{display:inline-block;padding:2px 8px;border-radius:12px;font-size:.72rem;font-weight:600;text-transform:uppercase}
.badge.active{background:#dcfce7;color:#166534}
.badge.paused{background:#fef9c3;color:#854d0e}
.badge.closed{background:#fee2e2;color:#991b1b}
.badge.under_review{background:#dbeafe;color:#1e40af}
.pagination{display:flex;align-items:center;gap:8px;margin-top:16px;justify-content:flex-end}
.pagination span{font-size:.85rem;color:#666}
#msg{padding:40px;text-align:center;color:#888;font-size:.9rem}
#error{background:#fee2e2;color:#991b1b;padding:12px 16px;border-radius:8px;margin-bottom:16px;display:none}
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
  <a href="produtos.php" class="active">Produtos</a>
  <a href="pedidos.php">Pedidos</a>
  <a href="configuracoes.php">Configurações</a>
  <a href="logs.php">Logs</a>
  <a href="../../admin/">← Admin</a>
</nav>
<div class="container">
  <div class="toolbar">
    <select id="statusFilter">
      <option value="active">Ativos</option>
      <option value="paused">Pausados</option>
      <option value="closed">Encerrados</option>
      <option value="under_review">Em revisão</option>
    </select>
    <select id="limitSel">
      <option value="10">10 por página</option>
      <option value="20" selected>20 por página</option>
      <option value="50">50 por página</option>
    </select>
    <button class="btn yellow" onclick="load(0)">Buscar</button>
    <span id="totalLabel" style="margin-left:auto;font-size:.85rem;color:#666"></span>
  </div>

  <div id="error"></div>

  <div id="tableWrap">
    <div id="msg">Clique em <strong>Buscar</strong> para carregar os produtos.</div>
  </div>

  <div class="pagination" id="pagination" style="display:none">
    <button class="btn sm" id="btnPrev" onclick="prevPage()">← Anterior</button>
    <span id="pageLabel"></span>
    <button class="btn sm" id="btnNext" onclick="nextPage()">Próxima →</button>
  </div>
</div>

<script>
let _offset = 0, _total = 0, _limit = 20;

function load(offset) {
  _offset = offset;
  _limit  = +document.getElementById('limitSel').value;
  const status = document.getElementById('statusFilter').value;
  document.getElementById('tableWrap').innerHTML = '<div id="msg">Carregando...</div>';
  document.getElementById('error').style.display = 'none';
  document.getElementById('pagination').style.display = 'none';
  document.getElementById('totalLabel').textContent = '';

  fetch(`../../api/ml/products.php?status=${status}&offset=${offset}&limit=${_limit}`)
    .then(r => r.json())
    .then(data => {
      if (data.error) { showError(data.message || data.error); return; }
      _total = data.total || 0;
      document.getElementById('totalLabel').textContent = `${_total} produto(s) encontrado(s)`;
      renderTable(data.items || []);
      renderPagination();
    })
    .catch(e => showError('Erro de rede: ' + e.message));
}

function showError(msg) {
  const el = document.getElementById('error');
  el.textContent = msg;
  el.style.display = 'block';
  document.getElementById('tableWrap').innerHTML = '';
}

function renderTable(items) {
  if (!items.length) {
    document.getElementById('tableWrap').innerHTML = '<div id="msg">Nenhum produto encontrado.</div>';
    return;
  }
  let html = `<table>
    <thead><tr>
      <th></th>
      <th>ID</th>
      <th>Título</th>
      <th>Status</th>
      <th>Preço</th>
      <th>Estoque</th>
      <th></th>
    </tr></thead><tbody>`;
  for (const it of items) {
    const thumb = it.thumbnail
      ? `<img class="thumb" src="${it.thumbnail}" alt="" loading="lazy">`
      : `<span class="thumb-placeholder">📦</span>`;
    const price = it.price != null ? `R$ ${Number(it.price).toFixed(2).replace('.',',')}` : '—';
    const qty   = it.available_quantity ?? '—';
    const badge = `<span class="badge ${it.status || ''}">${it.status || '—'}</span>`;
    const link  = it.permalink ? `<a href="${it.permalink}" target="_blank" class="btn sm">Ver</a>` : '';
    html += `<tr>
      <td>${thumb}</td>
      <td><code style="font-size:.75rem">${it.id || '—'}</code></td>
      <td style="max-width:340px;word-break:break-word">${esc(it.title || '—')}</td>
      <td>${badge}</td>
      <td>${esc(price)}</td>
      <td>${qty}</td>
      <td>${link}</td>
    </tr>`;
  }
  html += '</tbody></table>';
  document.getElementById('tableWrap').innerHTML = html;
}

function renderPagination() {
  if (_total <= _limit) { document.getElementById('pagination').style.display = 'none'; return; }
  const page  = Math.floor(_offset / _limit) + 1;
  const pages = Math.ceil(_total / _limit);
  document.getElementById('pageLabel').textContent = `Página ${page} de ${pages}`;
  document.getElementById('btnPrev').disabled = _offset === 0;
  document.getElementById('btnNext').disabled = (_offset + _limit) >= _total;
  document.getElementById('pagination').style.display = 'flex';
}

function prevPage() { if (_offset > 0) load(Math.max(0, _offset - _limit)); }
function nextPage() { if ((_offset + _limit) < _total) load(_offset + _limit); }
function esc(s) { const d = document.createElement('div'); d.textContent = s; return d.innerHTML; }

// Auto-load on page ready
load(0);
</script>
</body>
</html>

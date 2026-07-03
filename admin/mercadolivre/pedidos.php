<?php declare(strict_types=1); ?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Pedidos ML — ShopVivaliz</title>
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
.toolbar select{padding:7px 12px;border:1px solid #ddd;border-radius:6px;font-size:.875rem;background:#fff}
.btn{display:inline-block;padding:8px 16px;border-radius:6px;font-size:.85rem;font-weight:600;text-decoration:none;cursor:pointer;border:none;background:#333;color:#fff;transition:.15s}
.btn:hover{background:#555}
.btn.yellow{background:#fff059;color:#333}.btn.yellow:hover{background:#ffe000}
.btn.sm{padding:5px 10px;font-size:.78rem}
table{width:100%;border-collapse:collapse;background:#fff;border-radius:8px;overflow:hidden;box-shadow:0 1px 3px rgba(0,0,0,.1)}
th{background:#f9f9f9;text-align:left;padding:10px 14px;font-size:.78rem;text-transform:uppercase;letter-spacing:.04em;color:#666;border-bottom:1px solid #eee}
td{padding:10px 14px;font-size:.875rem;border-bottom:1px solid #f0f0f0;vertical-align:middle}
tr:last-child td{border-bottom:none}
tr:hover td{background:#fafafa}
.badge{display:inline-block;padding:2px 8px;border-radius:12px;font-size:.72rem;font-weight:600;text-transform:uppercase}
.badge.paid{background:#dcfce7;color:#166534}
.badge.payment_required{background:#fef9c3;color:#854d0e}
.badge.cancelled{background:#fee2e2;color:#991b1b}
.badge.confirmed,.badge.delivered{background:#dbeafe;color:#1e40af}
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
  <a href="produtos.php">Produtos</a>
  <a href="pedidos.php" class="active">Pedidos</a>
  <a href="configuracoes.php">Configurações</a>
  <a href="logs.php">Logs</a>
  <a href="../../admin/">← Admin</a>
</nav>
<div class="container">
  <div class="toolbar">
    <select id="limitSel">
      <option value="10">10 por página</option>
      <option value="20" selected>20 por página</option>
      <option value="50">50 por página</option>
    </select>
    <button class="btn yellow" onclick="load(0)">Atualizar</button>
    <span id="totalLabel" style="margin-left:auto;font-size:.85rem;color:#666"></span>
  </div>

  <div id="error"></div>

  <div id="tableWrap">
    <div id="msg">Carregando pedidos...</div>
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
  document.getElementById('tableWrap').innerHTML = '<div id="msg">Carregando...</div>';
  document.getElementById('error').style.display = 'none';
  document.getElementById('pagination').style.display = 'none';
  document.getElementById('totalLabel').textContent = '';

  fetch(`../../api/ml/orders.php?offset=${offset}&limit=${_limit}`)
    .then(r => r.json())
    .then(data => {
      if (data.error) { showError(data.message || data.error); return; }
      _total = data.total || 0;
      document.getElementById('totalLabel').textContent = `${_total} pedido(s) no total`;
      renderTable(data.orders || []);
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

function renderTable(orders) {
  if (!orders.length) {
    document.getElementById('tableWrap').innerHTML = '<div id="msg">Nenhum pedido encontrado.</div>';
    return;
  }
  let html = `<table>
    <thead><tr>
      <th>ID</th>
      <th>Status</th>
      <th>Total</th>
      <th>Comprador</th>
      <th>Produto</th>
      <th>Itens</th>
      <th>Data</th>
    </tr></thead><tbody>`;
  for (const o of orders) {
    const total  = o.total != null ? `${o.currency || 'BRL'} ${Number(o.total).toFixed(2).replace('.',',')}` : '—';
    const status = o.status || 'unknown';
    const badge  = `<span class="badge ${status.replace(/\s/g,'_')}">${status}</span>`;
    const date   = o.date ? new Date(o.date).toLocaleString('pt-BR') : '—';
    html += `<tr>
      <td><code style="font-size:.75rem">${o.id || '—'}</code></td>
      <td>${badge}</td>
      <td>${esc(total)}</td>
      <td>${esc(o.buyer || '—')}</td>
      <td style="max-width:260px;word-break:break-word;font-size:.8rem">${esc(o.item_title || '—')}</td>
      <td style="text-align:center">${o.items_count ?? 0}</td>
      <td style="white-space:nowrap;font-size:.8rem">${date}</td>
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

load(0);
</script>
</body>
</html>

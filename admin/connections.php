<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/admin-guard.php';
?>
<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Conexões e tokens — ShopVivaliz</title>
    <link rel="stylesheet" href="/css/style.css">
    <link rel="stylesheet" href="/css/admin-zoom-responsive.css?v=20260719-1">
    <style>
        .connection-page{max-width:1280px;margin:0 auto;padding:32px 20px 64px}.connection-head{display:flex;justify-content:space-between;gap:20px;align-items:flex-start;margin-bottom:24px}.connection-head h1{margin:.25rem 0}.connection-actions{display:flex;gap:10px;flex-wrap:wrap}.connection-btn{border:0;border-radius:8px;padding:11px 16px;font-weight:700;cursor:pointer;background:#1d4ed8;color:#fff}.connection-btn.alt{background:#e2e8f0;color:#0f172a}.connection-summary{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:14px;margin-bottom:20px}.connection-stat,.connection-table-wrap,.connection-history{background:#fff;border:1px solid #dbe3ef;border-radius:14px;padding:18px;box-shadow:0 5px 18px #0f172a0b}.connection-stat strong{display:block;font-size:2rem}.connection-stat span{color:#64748b;font-size:.9rem}.connection-table-wrap{overflow:auto}.connection-table{width:100%;border-collapse:collapse;min-width:850px}.connection-table th,.connection-table td{padding:14px 10px;text-align:left;border-bottom:1px solid #e2e8f0;vertical-align:top}.connection-table th{font-size:.78rem;text-transform:uppercase;letter-spacing:.04em;color:#64748b}.connection-badge{display:inline-flex;padding:5px 9px;border-radius:999px;font-size:.78rem;font-weight:800}.connected{background:#dcfce7;color:#166534}.failed{background:#fee2e2;color:#991b1b}.attention,.not_configured{background:#fef3c7;color:#92400e}.token-line{font-family:ui-monospace,monospace;font-size:.8rem;color:#475569}.connection-muted{color:#64748b;font-size:.85rem}.connection-history{margin-top:20px}.history-row{display:grid;grid-template-columns:180px 1fr 1fr 1fr;gap:12px;padding:8px 0;border-bottom:1px solid #eef2f7;font-size:.85rem}.connection-alert{background:#fff7ed;border:1px solid #fed7aa;color:#9a3412;border-radius:10px;padding:12px;margin-bottom:18px}.sr-only{position:absolute;width:1px;height:1px;padding:0;margin:-1px;overflow:hidden;clip:rect(0,0,0,0);white-space:nowrap;border:0}@media(max-width:800px){.connection-head{display:block}.connection-summary{grid-template-columns:repeat(2,minmax(0,1fr))}.history-row{grid-template-columns:1fr 1fr}.connection-actions{margin-top:15px}}
    </style>
</head>
<body>
<nav class="navbar" style="background:#1a1a2e;padding:1rem 0"><div class="container nav-inner" style="display:flex;justify-content:space-between;align-items:center"><a class="brand-link" href="/admin/" style="color:#fff;font-weight:bold">🛍️ ShopVivaliz Admin</a><div class="navbar-menu"><a href="/admin/" style="color:#fff;margin-right:14px">Central</a><a href="/auth/logout.php" style="color:#fca5a5">Sair</a></div></div></nav>
<main class="connection-page">
    <div class="connection-head"><div><p class="eyebrow">Observabilidade protegida</p><h1>Conexões e tokens</h1><p class="muted">Tokens aparecem somente mascarados. O monitor horário tenta renovações OAuth seguras e registra a causa quando a correção exige nova autorização.</p></div><div class="connection-actions"><button class="connection-btn" id="refresh" type="button">Atualizar agora</button><a class="connection-btn alt" href="/admin/">Voltar ao painel</a></div></div>
    <div id="alert" class="connection-alert" hidden></div>
    <section class="connection-summary" aria-label="Resumo"><div class="connection-stat"><strong id="total">—</strong><span>conexões monitoradas</span></div><div class="connection-stat"><strong id="connected">—</strong><span>conectadas</span></div><div class="connection-stat"><strong id="failed">—</strong><span>falharam</span></div><div class="connection-stat"><strong id="attention">—</strong><span>atenção / configuração</span></div></section>
    <section class="connection-table-wrap"><table class="connection-table"><caption class="sr-only">Status das integrações e metadados dos tokens</caption><thead><tr><th>Conexão</th><th>Status</th><th>Tokens</th><th>Última validação</th><th>Correção / próximo passo</th></tr></thead><tbody id="rows"><tr><td colspan="5">Carregando…</td></tr></tbody></table></section>
    <section class="connection-history"><h2>Histórico das últimas 48 validações</h2><div id="history" class="connection-muted">Carregando histórico…</div></section>
</main>
<script>
const escapeHtml=(value)=>String(value??'').replace(/[&<>'"]/g,(c)=>({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#39;','"':'&quot;'}[c]));
const label={connected:'Conectado',failed:'Falhou',attention:'Atenção',not_configured:'Não configurado'};
function render(data){
  const summary=data.summary||{}; for(const [id,key] of [['total','total'],['connected','connected'],['failed','failed'],['attention','attention']]) document.getElementById(id).textContent=summary[key]??'—';
  const rows=(data.integrations||[]).map(item=>{const tokens=Object.entries(item.tokens||{}).map(([key,val])=>`<div class="token-line">${escapeHtml(key)}: ${escapeHtml(val.mask||'não configurado')}</div>`).join(''); return `<tr><td><strong>${escapeHtml(item.name)}</strong><div class="connection-muted">${escapeHtml(item.message||'')}</div></td><td><span class="connection-badge ${escapeHtml(item.status)}">${escapeHtml(label[item.status]||item.status)}</span></td><td>${tokens||'—'}</td><td>${escapeHtml(data.checked_at||'—')}</td><td>${escapeHtml(item.remediation||((item.fixes||[]).join(' ')||'Sem ação pendente'))}</td></tr>`}).join('');
  document.getElementById('rows').innerHTML=rows||'<tr><td colspan="5">Nenhuma conexão registrada.</td></tr>';
  const history=(data.history||[]).slice().reverse().map(row=>`<div class="history-row"><span>${escapeHtml(row.checked_at)}</span><span>✅ ${row.connected}</span><span>❌ ${row.failed}</span><span>⚠️ ${row.attention}</span></div>`).join(''); document.getElementById('history').innerHTML=history||'Nenhuma validação registrada.';
  const alert=document.getElementById('alert'); const failures=(data.integrations||[]).filter(item=>item.status==='failed'); alert.hidden=failures.length===0; alert.textContent=failures.length?`Falha detectada: ${failures.map(item=>`${item.name} — ${item.remediation||item.message}`).join(' | ')}`:'';
}
async function load(refresh=false){const button=document.getElementById('refresh');button.disabled=true;button.textContent='Validando…';try{const response=await fetch('/api/admin/integrations-status.php'+(refresh?'?refresh=1':''),{cache:'no-store'});const data=await response.json();render(data)}catch(error){document.getElementById('alert').hidden=false;document.getElementById('alert').textContent='Não foi possível carregar o status agora.'}finally{button.disabled=false;button.textContent='Atualizar agora'}}
document.getElementById('refresh').addEventListener('click',()=>load(true)); load();
</script>
</body>
</html>

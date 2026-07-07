<?php
declare(strict_types=1);
header('Content-Type: text/html; charset=UTF-8');
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Monitor do Dev - ShopVivaliz</title>
    <link rel="stylesheet" href="/css/style.css">
    <style>
        .monitor-wrap{max-width:1180px;margin:0 auto;padding:28px 18px 56px}.monitor-head{display:flex;justify-content:space-between;gap:16px;align-items:flex-start;margin-bottom:22px}.monitor-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:16px}.monitor-card{background:#fff;border:1px solid #e5e7eb;border-radius:16px;padding:18px;box-shadow:0 10px 24px rgba(15,23,42,.08)}.monitor-card h2{margin:0 0 10px;font-size:22px}.monitor-pill{display:inline-flex;border-radius:999px;padding:7px 11px;font-weight:800;background:#e5e7eb;color:#111827}.monitor-pill[data-state="success"]{background:#dcfce7;color:#166534}.monitor-pill[data-state="warning"]{background:#fef3c7;color:#92400e}.monitor-pill[data-state="error"]{background:#fee2e2;color:#991b1b}.monitor-list{margin:12px 0 0;padding-left:20px;color:#4b5563}.monitor-list li{margin:7px 0}.monitor-actions{display:flex;flex-wrap:wrap;gap:10px;margin-top:14px}.monitor-pre{white-space:pre-wrap;word-break:break-word;background:#0f172a;color:#e5e7eb;border-radius:12px;padding:12px;max-height:260px;overflow:auto;font-size:12px}.muted-small{color:#6b7280;font-size:13px}@media(max-width:900px){.monitor-grid{grid-template-columns:1fr}.monitor-head{display:block}.monitor-wrap{padding:18px 12px 44px}.monitor-card{padding:15px}.monitor-card h2{font-size:20px}}
    </style>
</head>
<body>
<nav class="navbar">
    <div class="container nav-inner">
        <a class="brand-link" href="/admin/">ShopVivaliz Admin</a>
        <div class="navbar-menu">
            <a href="/admin/">Admin</a>
            <a href="/catalogo.php">Catálogo</a>
            <a href="/checkout">Checkout</a>
            <a href="/admin/monitor/">Monitor</a>
        </div>
    </div>
</nav>
<main class="monitor-wrap">
    <section class="monitor-head">
        <div>
            <p class="eyebrow">Operação técnica</p>
            <h1>Monitor do Dev</h1>
            <p class="muted">Ambiente Ubuntu, Apache/PHP, health checks, watchdog, filas, backlog, roadmap e status do agente de desenvolvimento.</p>
        </div>
        <button class="btn btn-primary" id="refresh-monitor" type="button">Atualizar agora</button>
    </section>

    <section class="monitor-grid" aria-live="polite">
        <article class="monitor-card">
            <div class="admin-card-head">
                <h2>Ambiente Ubuntu</h2>
                <span class="monitor-pill" id="env-pill">Carregando...</span>
            </div>
            <ul class="monitor-list" id="env-list"><li>Consultando /api/health.php...</li></ul>
            <div class="monitor-actions">
                <a class="btn btn-secondary" href="/api/health.php" target="_blank" rel="noreferrer">JSON health</a>
            </div>
        </article>

        <article class="monitor-card">
            <div class="admin-card-head">
                <h2>Agente de desenvolvimento</h2>
                <span class="monitor-pill" id="dev-pill">Carregando...</span>
            </div>
            <ul class="monitor-list" id="dev-list"><li>Consultando status do agente...</li></ul>
            <div class="monitor-actions">
                <a class="btn btn-secondary" href="/api/monitor/dev-status.php" target="_blank" rel="noreferrer">JSON dev</a>
                <a class="btn btn-secondary" href="/admin/squad-chat.php" target="_blank" rel="noreferrer">Squad Chat</a>
                <a class="btn btn-secondary" href="/admin/ai-pipeline.html" target="_blank" rel="noreferrer">AI Pipeline</a>
            </div>
        </article>

        <article class="monitor-card">
            <div class="admin-card-head">
                <h2>Tempo real VS Code/Codex</h2>
                <span class="monitor-pill" id="rt-pill">Pronto</span>
            </div>
            <p class="muted-small">Comandos recomendados no terminal do servidor:</p>
            <pre class="monitor-pre">tail -f /home/ubuntu/site-shopvivaliz/logs/watchdog.log
tail -f /home/ubuntu/site-shopvivaliz/logs/dev-agent.log
tail -f /var/log/apache2/error.log</pre>
        </article>
    </section>
</main>
<script>
(function(){
    function setPill(id, text, state){var n=document.getElementById(id); if(!n) return; n.textContent=text; n.dataset.state=state || 'warning';}
    function setList(id, items){var n=document.getElementById(id); if(!n) return; n.innerHTML=items.map(function(x){return '<li>'+x+'</li>';}).join('');}
    async function readJson(url){var r=await fetch(url,{cache:'no-store'}); var t=await r.text(); try{return {http:r.status,json:JSON.parse(t)}}catch(e){return {http:r.status,json:null,raw:t}}}
    function yn(v){return v ? 'OK' : 'Atenção';}
    async function load(){
        try{
            var health=await readJson('/api/health.php');
            var h=health.json || {};
            setPill('env-pill', h.ok ? 'Operacional' : 'Atenção', h.ok ? 'success' : 'warning');
            setList('env-list', [
                'HTTP health: '+health.http,
                'PHP: '+((h.php && h.php.version) || 'n/d'),
                'Servidor: '+((h.server && h.server.software) || 'n/d'),
                'Disco usado: '+((h.disk && h.disk.used_percent) || 'n/d')+'%',
                'Logs graváveis: '+yn(h.checks && h.checks['Diretorio logs gravavel']),
                'Monitor presente: '+yn(h.checks && h.checks['Monitor admin presente'])
            ]);
        }catch(e){setPill('env-pill','Falhou','error');setList('env-list',['Não foi possível carregar /api/health.php.']);}
        try{
            var dev=await readJson('/api/monitor/dev-status.php');
            var d=dev.json || {};
            setPill('dev-pill', d.ok ? 'Operacional' : 'Atenção', d.ok ? 'success' : 'warning');
            setList('dev-list', [
                'HTTP dev-status: '+dev.http,
                'Watchdog: '+yn(d.checks && d.checks['Watchdog configurado']),
                'Backlog: '+yn(d.checks && d.checks['Backlog localizado']),
                'Roadmap: '+yn(d.checks && d.checks['Roadmap localizado']),
                'Logs graváveis: '+yn(d.checks && d.checks['Diretorio logs gravavel'])
            ]);
        }catch(e){setPill('dev-pill','Falhou','error');setList('dev-list',['Não foi possível carregar o status do agente.']);}
        setPill('rt-pill','Pronto','success');
    }
    document.getElementById('refresh-monitor').addEventListener('click', load);
    load();
})();
</script>
</body>
</html>

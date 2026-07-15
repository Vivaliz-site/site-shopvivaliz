<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/admin-guard.php';
header('Content-Type: text/html; charset=UTF-8');
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Central de Agentes - ShopVivaliz</title>
    <link rel="stylesheet" href="/css/style.css">
    <style>
        .ops-shell{max-width:1380px;margin:0 auto;padding:24px 18px 48px}
        .ops-head{display:flex;justify-content:space-between;gap:16px;align-items:flex-start;margin-bottom:18px}
        .ops-grid{display:grid;grid-template-columns:1.05fr 1.35fr;gap:16px}
        .ops-stack{display:grid;gap:16px}
        .ops-card{background:#fff;border:1px solid #e5e7eb;border-radius:18px;box-shadow:0 14px 32px rgba(15,23,42,.08);padding:18px}
        .ops-title{display:flex;justify-content:space-between;align-items:center;gap:12px;margin-bottom:12px}
        .ops-title h2,.ops-title h3{margin:0}
        .ops-pill{display:inline-flex;align-items:center;gap:8px;border-radius:999px;padding:7px 12px;font-weight:700;background:#e5e7eb;color:#111827}
        .ops-pill.success{background:#dcfce7;color:#166534}.ops-pill.warn{background:#fef3c7;color:#92400e}.ops-pill.error{background:#fee2e2;color:#991b1b}
        .ops-kpis{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px}
        .ops-kpi{border:1px solid #e5e7eb;border-radius:14px;padding:14px;background:#f8fafc}
        .ops-kpi strong{display:block;font-size:28px;line-height:1;margin-bottom:6px}
        .agents-list{display:grid;gap:12px}
        .agent-item{border:1px solid #e5e7eb;border-radius:16px;padding:14px;background:#fff}
        .agent-top{display:flex;justify-content:space-between;gap:12px;align-items:flex-start;margin-bottom:8px}
        .agent-meta{font-size:13px;color:#64748b}
        .agent-task,.agent-note{font-size:14px;color:#0f172a;margin-top:8px}
        .agent-actions{display:flex;gap:8px;flex-wrap:wrap;margin-top:12px}
        .agent-actions button,.ops-btn{border:0;border-radius:12px;padding:10px 14px;font-weight:700;cursor:pointer}
        .ops-btn.primary{background:#1d4ed8;color:#fff}.ops-btn.secondary{background:#e2e8f0;color:#0f172a}.ops-btn.success{background:#16a34a;color:#fff}
        .chat-layout{display:grid;grid-template-columns:320px 1fr;gap:14px}
        .agent-tabs{display:grid;gap:8px}
        .agent-tab{border:1px solid #cbd5e1;background:#fff;border-radius:14px;padding:12px;text-align:left;cursor:pointer}
        .agent-tab.active{border-color:#1d4ed8;box-shadow:0 0 0 2px rgba(29,78,216,.14)}
        .agent-thread{border:1px solid #e5e7eb;border-radius:16px;padding:14px;background:#f8fafc;min-height:420px;display:flex;flex-direction:column}
        .thread-log{flex:1;overflow:auto;display:grid;gap:10px;max-height:420px;padding-right:4px}
        .bubble{border-radius:14px;padding:10px 12px;font-size:14px;line-height:1.45}
        .bubble.user{background:#dbeafe;color:#1e3a8a}.bubble.agent{background:#fff;color:#0f172a;border:1px solid #e2e8f0}
        .thread-form{display:grid;grid-template-columns:1fr auto;gap:10px;margin-top:12px}
        .thread-form textarea{min-height:94px;border:1px solid #cbd5e1;border-radius:14px;padding:12px;resize:vertical}
        .thread-status{margin-top:10px;font-size:13px;color:#475569;min-height:20px}
        .thread-status.success{color:#166534}
        .thread-status.error{color:#991b1b}
        .log-pre{white-space:pre-wrap;word-break:break-word;background:#0f172a;color:#e5e7eb;border-radius:14px;padding:12px;font-size:12px;max-height:260px;overflow:auto}
        .agent-timeline{margin-top:12px;background:#0f172a;border-radius:14px;padding:10px 12px;max-height:190px;overflow:auto;border:1px solid #1e293b}
        .timeline-line{font:12px/1.45 Consolas,Monaco,monospace;color:#dbeafe;padding:4px 0;border-bottom:1px solid rgba(148,163,184,.14)}
        .timeline-line:last-child{border-bottom:0}
        .timeline-empty{font:12px/1.45 Consolas,Monaco,monospace;color:#94a3b8}
        @media (max-width: 1080px){.ops-grid,.chat-layout{grid-template-columns:1fr}.ops-kpis{grid-template-columns:repeat(2,minmax(0,1fr))}}
        @media (max-width: 680px){.ops-shell{padding:16px 12px 40px}.ops-kpis{grid-template-columns:1fr}.ops-head{display:block}}
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
            <a href="/admin/monitor/">Central de Agentes</a>
        </div>
    </div>
</nav>

<main class="ops-shell">
    <section class="ops-head">
        <div>
            <p class="eyebrow">Operação autônoma</p>
            <h1>Central de Agentes</h1>
            <p class="muted">Status ao vivo, backlog real, heartbeat individual, timeline interna e comunicação direta com cada agente.</p>
        </div>
        <div class="agent-actions">
            <button class="ops-btn secondary" id="refresh-all" type="button">Atualizar agora</button>
            <button class="ops-btn success" id="generate-tasks" type="button">Gerar novas tarefas</button>
        </div>
    </section>

    <section class="ops-grid">
        <div class="ops-stack">
            <article class="ops-card">
                <div class="ops-title">
                    <h2>Visão Geral</h2>
                    <span class="ops-pill" id="overall-pill">Carregando</span>
                </div>
                <div class="ops-kpis">
                    <div class="ops-kpi"><span>Fila total</span><strong id="kpi-total">-</strong><small id="kpi-source">sem fonte</small></div>
                    <div class="ops-kpi"><span>Pendentes</span><strong id="kpi-pending">-</strong><small>tarefas a atacar</small></div>
                    <div class="ops-kpi"><span>Ativas</span><strong id="kpi-active">-</strong><small>em execução</small></div>
                    <div class="ops-kpi"><span>Concluídas</span><strong id="kpi-completed">-</strong><small>já entregues</small></div>
                </div>
                <div class="agent-note" id="overall-note" style="margin-top:12px">Aguardando leitura do monitor.</div>
            </article>

            <article class="ops-card">
                <div class="ops-title">
                    <h2>Agentes ao Vivo</h2>
                    <span class="ops-pill" id="agents-pill">...</span>
                </div>
                <div class="agents-list" id="agents-list"></div>
            </article>

            <article class="ops-card">
                <div class="ops-title">
                    <h2>Eventos Recentes</h2>
                    <span class="ops-pill success">Stream</span>
                </div>
                <pre class="log-pre" id="events-log">Carregando histórico...</pre>
            </article>
        </div>

        <div class="ops-stack">
            <article class="ops-card">
                <div class="ops-title">
                    <h2>Falar com um agente</h2>
                    <span class="ops-pill" id="chat-pill">Pronto</span>
                </div>
                <div class="chat-layout">
                    <div class="agent-tabs" id="agent-tabs"></div>
                    <div class="agent-thread">
                        <div class="thread-log" id="thread-log"></div>
                        <form class="thread-form" id="thread-form">
                            <textarea id="thread-message" placeholder="Envie uma instrução direta para o agente selecionado..."></textarea>
                            <button class="ops-btn primary" type="submit">Enviar</button>
                        </form>
                        <div class="thread-status" id="thread-status"></div>
                    </div>
                </div>
            </article>

            <article class="ops-card">
                <div class="ops-title">
                    <h2>Fila canônica</h2>
                    <span class="ops-pill" id="queue-pill">...</span>
                </div>
                <pre class="log-pre" id="queue-log">Carregando fila...</pre>
            </article>
        </div>
    </section>
</main>

<script>
(function(){
    const state = { agents: [], commands: [], responses: [], selectedAgent: 'claude' };

    function byId(id){ return document.getElementById(id); }
    function setThreadStatus(text, kind){
        const node = byId('thread-status');
        node.textContent = text || '';
        node.className = 'thread-status' + (kind ? ' ' + kind : '');
    }
    function pill(node, text, kind){
        node.textContent = text;
        node.className = 'ops-pill ' + (kind || '');
    }
    function esc(value){
        return String(value ?? '').replace(/[&<>"']/g, function(ch){
            return ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'})[ch];
        });
    }
    async function readJson(url, options){
        const response = await fetch(url, Object.assign({cache:'no-store'}, options || {}));
        const text = await response.text();
        let json = null;
        try { json = JSON.parse(text); } catch (e) {}
        if (!response.ok) throw new Error((json && json.message) || ('HTTP ' + response.status));
        return json;
    }

    function renderAgents(){
        const host = byId('agents-list');
        const tabs = byId('agent-tabs');
        if (!state.agents.length){
            host.innerHTML = '<div class="agent-item">Nenhum agente encontrado.</div>';
            tabs.innerHTML = '';
            return;
        }

        host.innerHTML = state.agents.map(function(agent){
            const hb = agent.heartbeat || {};
            const status = hb.alive ? 'ATIVO' : 'SEM BATIMENTO';
            const statusKind = hb.alive ? 'success' : 'error';
            const timeline = (agent.passos_execucao || []).slice().reverse().map(function(step){
                return '<div class="timeline-line">'+esc(step.label || step.message || '')+'</div>';
            }).join('') || '<div class="timeline-empty">Sem passos recentes.</div>';

            return '<div class="agent-item">'+
                '<div class="agent-top">'+
                    '<div><strong>'+esc(agent.name)+'</strong><div class="agent-meta">'+esc(agent.role)+'</div></div>'+
                    '<span class="ops-pill '+statusKind+'">'+status+'</span>'+
                '</div>'+
                '<div class="agent-meta">Heartbeat: '+esc(hb.last_heartbeat || 'nunca')+' | idade: '+esc(hb.age_s ?? '-')+'s | tarefas processadas: '+esc(hb.tasks_processed ?? 0)+'</div>'+
                '<div class="agent-task"><strong>Foco:</strong> '+esc(agent.current_focus || hb.current_focus || 'Aguardando')+'</div>'+
                '<div class="agent-note"><strong>Ação atual:</strong> '+esc(agent.current_action || 'Aguardando')+'</div>'+
                '<div class="agent-note"><strong>Backlog de comandos:</strong> '+esc(agent.command_backlog ?? 0)+'</div>'+
                '<div class="agent-note"><strong>Última resposta:</strong> '+esc(agent.latest_response ? (agent.latest_response.message || agent.latest_response.reply || agent.latest_response.answer || '') : 'sem resposta ainda')+'</div>'+
                '<div class="agent-timeline">'+timeline+'</div>'+
            '</div>';
        }).join('');

        tabs.innerHTML = state.agents.map(function(agent){
            const active = state.selectedAgent === agent.id ? ' active' : '';
            return '<button class="agent-tab'+active+'" data-agent="'+esc(agent.id)+'"><strong>'+esc(agent.name)+'</strong><br><span class="agent-meta">'+esc(agent.role)+'</span></button>';
        }).join('');

        tabs.querySelectorAll('[data-agent]').forEach(function(button){
            button.addEventListener('click', function(){
                state.selectedAgent = button.getAttribute('data-agent');
                renderAgents();
                renderThread();
            });
        });
    }

    function renderThread(){
        const log = byId('thread-log');
        const selected = state.selectedAgent;
        const relevant = []
            .concat(state.commands.filter(x => (x.agent_id || '').toLowerCase() === selected).map(x => ({type:'user', ts:x.created_at, text:x.message})))
            .concat(state.responses.filter(x => {
                const agent = String(x.agent || x.agent_id || '').toLowerCase();
                return agent === selected || (selected === 'gpt' && agent === 'chatgpt');
            }).map(x => ({type:'agent', ts:x.timestamp || x.created_at, text:x.message || x.reply || x.answer || ''})))
            .sort((a,b) => String(a.ts || '').localeCompare(String(b.ts || '')));

        if (!relevant.length){
            log.innerHTML = '<div class="bubble agent">Nenhuma conversa registrada com este agente ainda.</div>';
            return;
        }
        log.innerHTML = relevant.map(function(item){
            return '<div class="bubble '+(item.type === 'user' ? 'user' : 'agent')+'"><strong>'+(item.type === 'user' ? 'Você' : 'Agente')+'</strong><br>'+esc(item.text)+'</div>';
        }).join('');
        log.scrollTop = log.scrollHeight;
    }

    function renderQueue(tasks, source){
        byId('queue-log').textContent = JSON.stringify({source: source, tasks: tasks}, null, 2);
    }

    function renderEvents(commands, responses){
        byId('events-log').textContent = JSON.stringify(commands.concat(responses).slice(-25), null, 2);
    }

    async function loadAll(){
        try{
            const [status, agents, messages, tasks] = await Promise.all([
                readJson('/api/monitor/api.php?action=status'),
                readJson('/api/monitor/api.php?action=agents'),
                readJson('/api/monitor/api.php?action=messages'),
                readJson('/api/monitor/api.php?action=tasks')
            ]);

            const queue = status.queue || {};
            byId('kpi-total').textContent = queue.total ?? '-';
            byId('kpi-pending').textContent = queue.pending ?? '-';
            byId('kpi-active').textContent = queue.active ?? '-';
            byId('kpi-completed').textContent = queue.completed ?? '-';
            byId('kpi-source').textContent = queue.source || 'sem fonte';
            byId('overall-note').textContent = 'Ciclo: ' + (status.details?.cycle_status || 'n/d') + ' | fonte fila: ' + (queue.source || 'n/d');
            pill(byId('overall-pill'), (status.autonomous_status?.status || 'unknown').toUpperCase(), status.autonomous_status?.status === 'healthy' ? 'success' : (status.autonomous_status?.status === 'warning' ? 'warn' : 'error'));
            pill(byId('queue-pill'), 'Fonte: ' + (tasks.source || 'n/d'), 'success');

            state.agents = agents.agents || [];
            state.commands = messages.commands || [];
            state.responses = messages.responses || [];
            if (!state.agents.find(agent => agent.id === state.selectedAgent) && state.agents[0]) {
                state.selectedAgent = state.agents[0].id;
            }

            pill(byId('agents-pill'), state.agents.length + ' agentes', 'success');
            renderAgents();
            renderThread();
            renderQueue(tasks.tasks || [], tasks.source || null);
            renderEvents(state.commands, state.responses);
            pill(byId('chat-pill'), 'Online', 'success');
            const latestResponse = (state.responses || []).filter(function(item){
                const agent = String(item.agent || item.agent_id || '').toLowerCase();
                return agent === state.selectedAgent || (state.selectedAgent === 'gpt' && agent === 'chatgpt');
            }).slice(-1)[0];
            if (latestResponse) {
                setThreadStatus('Ultima resposta recebida em ' + String(latestResponse.timestamp || latestResponse.created_at || '').replace('T', ' ').replace('Z', ''), 'success');
            }
        } catch (error) {
            pill(byId('overall-pill'), 'Falha', 'error');
            pill(byId('chat-pill'), 'Falha', 'error');
            byId('events-log').textContent = String(error);
            setThreadStatus('Falha ao atualizar dados do monitor.', 'error');
        }
    }

    byId('thread-form').addEventListener('submit', async function(event){
        event.preventDefault();
        const message = byId('thread-message').value.trim();
        if (!message) return;
        const agentId = state.selectedAgent;
        try{
            setThreadStatus('Enviando comando para ' + agentId + '...', '');
            await readJson('/api/monitor/api.php?action=send-command', {
                method: 'POST',
                headers: {'Content-Type':'application/json'},
                body: JSON.stringify({agent_id: agentId, message: message, source: 'central-agentes'})
            });
            byId('thread-message').value = '';
            setThreadStatus('Comando enviado. Aguardando resposta do agente...', 'success');
            await loadAll();
        } catch (error) {
            setThreadStatus('Falha ao enviar comando: ' + error.message, 'error');
        }
    });

    byId('refresh-all').addEventListener('click', loadAll);
    byId('generate-tasks').addEventListener('click', async function(){
        try{
            const result = await readJson('/api/monitor/api.php?action=generate-tasks', {
                method: 'POST',
                headers: {'Content-Type':'application/json'},
                body: JSON.stringify({source: 'central-agentes'})
            });
            alert(result.message || 'Geração executada.');
            await loadAll();
        } catch (error) {
            alert('Falha ao gerar tarefas: ' + error.message);
        }
    });

    loadAll();
    setInterval(loadAll, 2000);
})();
</script>
</body>
</html>

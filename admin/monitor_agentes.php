<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/admin-guard.php';
header('Content-Type: text/html; charset=UTF-8');
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Monitor de Agentes - ShopVivaliz</title>
    <style>
        :root{color-scheme:light;font-family:Arial,sans-serif}
        body{margin:0;background:#f3f6fb;color:#0f172a}
        .wrap{max-width:1440px;margin:0 auto;padding:20px}
        .head{display:flex;justify-content:space-between;align-items:flex-start;gap:16px;margin-bottom:20px}
        .head h1{margin:0 0 8px;font-size:32px}
        .muted{color:#64748b;margin:0}
        .toolbar{display:flex;gap:10px;flex-wrap:wrap}
        button{border:0;border-radius:12px;padding:10px 14px;font-weight:700;cursor:pointer}
        .primary{background:#2563eb;color:#fff}.secondary{background:#e2e8f0;color:#0f172a}
        .success{background:#16a34a;color:#fff}
        .stats{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px;margin-bottom:16px}
        .stat,.agent{background:#fff;border:1px solid #dbe1ea;border-radius:18px;box-shadow:0 14px 30px rgba(15,23,42,.07)}
        .stat{padding:16px}.stat strong{display:block;font-size:30px}
        .grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:16px}
        .agent{padding:16px;display:grid;gap:14px}
        .agent-top{display:flex;justify-content:space-between;align-items:flex-start;gap:12px}
        .badge{display:inline-flex;align-items:center;border-radius:999px;padding:6px 12px;font-size:12px;font-weight:700}
        .green{background:#dcfce7;color:#166534}.yellow{background:#fef3c7;color:#92400e}.red{background:#fee2e2;color:#991b1b}
        .task-list,.step-list{display:grid;gap:8px}
        .task,.step{border:1px solid #e2e8f0;border-radius:12px;padding:10px 12px;background:#f8fafc}
        .step time,.mini{font-size:12px;color:#64748b}
        .command{display:grid;grid-template-columns:1fr auto;gap:10px}
        .command textarea{min-height:84px;border:1px solid #cbd5e1;border-radius:12px;padding:10px;resize:vertical}
        .timeline{max-height:260px;overflow:auto;padding-right:4px}
        .footer-log{margin-top:16px;background:#0f172a;color:#dbeafe;border-radius:16px;padding:14px;white-space:pre-wrap;max-height:280px;overflow:auto}
        @media (max-width:1100px){.grid{grid-template-columns:1fr}.stats{grid-template-columns:repeat(2,minmax(0,1fr))}}
        @media (max-width:640px){.stats{grid-template-columns:1fr}.head{display:block}.command{grid-template-columns:1fr}}
    </style>
</head>
<body>
<main class="wrap">
    <section class="head">
        <div>
            <h1>Monitor de Agentes</h1>
            <p class="muted">Estado real da fila, passos ao vivo por agente e intervenção humana operacional.</p>
        </div>
        <div class="toolbar">
            <button class="secondary" id="refreshBtn" type="button">Atualizar agora</button>
            <button class="success" id="generateBtn" type="button">Forçar nova rodada</button>
        </div>
    </section>

    <section class="stats">
        <article class="stat"><span>Fila total</span><strong id="totalTasks">-</strong><small id="queueSource">-</small></article>
        <article class="stat"><span>Pendentes</span><strong id="pendingTasks">-</strong><small>backlog executável</small></article>
        <article class="stat"><span>Ativas</span><strong id="activeTasks">-</strong><small>em progresso</small></article>
        <article class="stat"><span>Concluídas</span><strong id="completedTasks">-</strong><small>entregas fechadas</small></article>
    </section>

    <section class="grid" id="agentsGrid"></section>
    <pre class="footer-log" id="footerLog">Carregando monitor...</pre>
</main>

<script>
(() => {
    const state = { agents: [], queue: null, messages: null };
    const byId = (id) => document.getElementById(id);
    const esc = (value) => String(value ?? '').replace(/[&<>"']/g, (ch) => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[ch]));

    async function readJson(url, options = {}) {
        const response = await fetch(url, { cache: 'no-store', ...options });
        const text = await response.text();
        let json = null;
        try { json = JSON.parse(text); } catch (e) {}
        if (!response.ok) throw new Error((json && json.message) || ('HTTP ' + response.status));
        return json;
    }

    function renderStats(status) {
        const queue = status.queue || {};
        byId('totalTasks').textContent = queue.total ?? '-';
        byId('pendingTasks').textContent = queue.pending ?? '-';
        byId('activeTasks').textContent = queue.active ?? '-';
        byId('completedTasks').textContent = queue.completed ?? '-';
        byId('queueSource').textContent = queue.source || 'fila sem fonte';
    }

    function renderAgents() {
        const grid = byId('agentsGrid');
        if (!state.agents.length) {
            grid.innerHTML = '<article class="agent">Nenhum agente encontrado.</article>';
            return;
        }

        grid.innerHTML = state.agents.map((agent) => {
            const tasks = (agent.assigned_tasks || []).map((task) =>
                `<div class="task"><strong>${esc(task.title)}</strong><div class="mini">${esc(task.status)} | prioridade ${esc(task.priority)}</div></div>`
            ).join('') || '<div class="task">Sem tarefas atribuídas.</div>';

            const steps = (agent.steps || []).slice().reverse().map((step) =>
                `<div class="step"><strong>${esc(step.action || 'Sem ação')}</strong><div class="mini">${esc(step.status || '')}</div><time>${esc(step.timestamp || '')}</time></div>`
            ).join('') || '<div class="step">Sem passos recentes.</div>';

            const latest = agent.latest_response ? (agent.latest_response.message || '') : 'Sem resposta recente.';
            return `
                <article class="agent">
                    <div class="agent-top">
                        <div>
                            <h2 style="margin:0 0 6px">${esc(agent.name)}</h2>
                            <div class="mini">${esc(agent.role || '')}</div>
                        </div>
                        <span class="badge ${esc(agent.status_color || 'yellow')}">${esc(agent.status || 'idle')}</span>
                    </div>
                    <div><strong>Ação atual:</strong> ${esc(agent.current_action || 'Aguardando')}</div>
                    <div class="mini">Heartbeat: ${esc(agent.heartbeat?.last_heartbeat || 'nunca')} | backlog de comandos: ${esc(agent.command_backlog ?? 0)}</div>
                    <div>
                        <strong>Tarefas</strong>
                        <div class="task-list">${tasks}</div>
                    </div>
                    <div>
                        <strong>Passos ao vivo</strong>
                        <div class="step-list timeline">${steps}</div>
                    </div>
                    <div><strong>Última resposta:</strong> ${esc(latest)}</div>
                    <form class="command" data-agent="${esc(agent.id)}">
                        <textarea placeholder="Envie uma ordem direta para ${esc(agent.name)}"></textarea>
                        <button class="primary" type="submit">Enviar comando</button>
                    </form>
                </article>
            `;
        }).join('');

        grid.querySelectorAll('form[data-agent]').forEach((form) => {
            form.addEventListener('submit', async (event) => {
                event.preventDefault();
                const agentId = form.getAttribute('data-agent');
                const textarea = form.querySelector('textarea');
                const message = textarea.value.trim();
                if (!message) return;
                textarea.value = '';
                try {
                    await readJson('/api/agent/squad-chat.php?mode=operations', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ agent_id: agentId, message, source: 'monitor_agentes' })
                    });
                    await load();
                } catch (error) {
                    alert('Falha ao enviar comando: ' + error.message);
                }
            });
        });
    }

    function renderFooter(status, messages) {
        const payload = {
            cycle: status.details || {},
            recent_commands: messages.commands ? messages.commands.slice(-8) : [],
            recent_responses: messages.responses ? messages.responses.slice(-8) : []
        };
        byId('footerLog').textContent = JSON.stringify(payload, null, 2);
    }

    async function load() {
        try {
            const [status, agents, messages] = await Promise.all([
                readJson('/api/monitor/api.php?action=status'),
                readJson('/api/monitor/api.php?action=agents'),
                readJson('/api/monitor/api.php?action=messages')
            ]);
            state.agents = agents.agents || [];
            state.messages = messages;
            renderStats(status);
            renderAgents();
            renderFooter(status, messages);
        } catch (error) {
            byId('footerLog').textContent = String(error);
        }
    }

    byId('refreshBtn').addEventListener('click', load);
    byId('generateBtn').addEventListener('click', async () => {
        try {
            await readJson('/api/monitor/api.php?action=generate-tasks', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ source: 'monitor_agentes' })
            });
            await load();
        } catch (error) {
            alert('Falha ao acionar executor: ' + error.message);
        }
    });

    load();
    setInterval(load, 2000);
})();
</script>
</body>
</html>

<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/admin-guard.php';
require_once __DIR__ . '/../includes/csrf.php';

$csrf = sv_csrf_token('marina-maintenance');
header('Content-Type: text/html; charset=UTF-8');
?>
<!doctype html>
<html lang="pt-BR">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Manutenção Marina | ShopVivaliz Admin</title>
    <style>
        :root { color-scheme: light; font-family: Inter, system-ui, sans-serif; }
        body { margin: 0; background: #f1f5f9; color: #172033; }
        .top { background: #101b33; color: #fff; padding: 16px clamp(16px, 5vw, 72px); display:flex; justify-content:space-between; gap:16px; flex-wrap:wrap; }
        .top a { color:#c7d2fe; }
        main { max-width: 1080px; margin: 32px auto; padding: 0 18px 64px; }
        .hero, .card { background:#fff; border:1px solid #dbe3ee; border-radius:16px; box-shadow:0 8px 24px rgba(15,23,42,.06); padding:24px; margin-bottom:18px; }
        .hero { border-left:6px solid #0f766e; }
        h1, h2 { margin-top:0; }
        .muted { color:#64748b; }
        .state { display:inline-flex; border-radius:999px; padding:8px 13px; font-weight:800; font-size:13px; background:#e2e8f0; color:#334155; }
        .state.good { background:#dcfce7; color:#166534; }
        .state.warn { background:#fef3c7; color:#92400e; }
        .state.bad { background:#fee2e2; color:#991b1b; }
        .grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(260px,1fr)); gap:14px; }
        .fact { background:#f8fafc; border-radius:12px; padding:14px; min-height:52px; }
        .fact strong, .fact span { display:block; }
        .fact strong { font-size:12px; text-transform:uppercase; letter-spacing:.04em; color:#64748b; margin-bottom:6px; }
        label { display:block; font-weight:700; margin:14px 0 6px; }
        textarea, input { box-sizing:border-box; width:100%; border:1px solid #cbd5e1; border-radius:10px; padding:11px; font:inherit; }
        textarea { min-height:150px; resize:vertical; }
        button, .button { border:0; border-radius:10px; padding:11px 15px; font-weight:800; cursor:pointer; background:#0f766e; color:#fff; text-decoration:none; display:inline-block; }
        button.secondary, .button.secondary { background:#334155; }
        button:disabled { cursor:not-allowed; opacity:.48; }
        .actions { display:flex; gap:10px; flex-wrap:wrap; margin-top:16px; }
        .notice { border-radius:10px; padding:12px 14px; background:#f8fafc; border:1px solid #e2e8f0; white-space:pre-wrap; }
        .danger-note { background:#fff7ed; border-color:#fed7aa; color:#9a3412; }
        code { background:#eef2ff; padding:2px 5px; border-radius:4px; }
    </style>
</head>
<body>
    <header class="top">
        <strong>ShopVivaliz Admin · Manutenção Marina</strong>
        <span><a href="/admin/">Voltar ao admin</a> · <a href="/auth/logout.php">Sair</a></span>
    </header>
    <main>
        <section class="hero">
            <h1>Autorização de manutenção Oracle Marina</h1>
            <p class="muted">Esta tela somente prepara e acompanha a operação oficial. A mudança só pode ocorrer pelo broker privado aprovado, com ticket de escopo fixo e assinatura externa.</p>
            <div id="state" class="state">Carregando estado…</div>
            <p id="message" class="notice" aria-live="polite">Nenhuma operação foi iniciada.</p>
        </section>

        <section class="card">
            <h2>Pré-verificação</h2>
            <div class="grid" id="facts">
                <div class="fact"><strong>Broker</strong><span id="broker">Aguardando leitura</span></div>
                <div class="fact"><strong>Escopo fixo</strong><span>shopvivaliz-free-a1 → marina</span></div>
                <div class="fact"><strong>Scheduler</strong><span>shopvivaliz-abandoned-cart-recovery.timer</span></div>
                <div class="fact"><strong>Mutação direta</strong><span>Bloqueada</span></div>
            </div>
            <div class="actions">
                <button type="button" id="refresh">Atualizar estado</button>
                <button type="button" class="secondary" id="preview">Executar pré-verificação</button>
            </div>
        </section>

        <section class="card">
            <h2>Assinatura externa</h2>
            <p class="muted">Cole somente o ticket assinado emitido pelo signatário oficial. Nunca cole ou envie a <strong>private key</strong>; ela não é necessária nesta tela e não deve aparecer no navegador, no servidor ou nos logs.</p>
            <label for="ticket">Ticket JSON</label>
            <textarea id="ticket" spellcheck="false" placeholder='{"ticket_version":1,"operation":"retire_origin_abandoned_cart_scheduler",...}'></textarea>
            <label for="signature">Assinatura destacada (base64)</label>
            <textarea id="signature" spellcheck="false" placeholder="Cole a assinatura destacada fornecida pelo signatário"></textarea>
            <div class="actions">
                <button type="button" id="approve" disabled>Aprovar operação oficial</button>
            </div>
            <div class="notice danger-note" id="approval-help">A aprovação permanece bloqueada até o broker retornar <code>APPROVAL_REQUIRED</code>, a sessão possuir reautenticação recente e os campos serem validados.</div>
        </section>

        <section class="card">
            <h2>Acompanhamento</h2>
            <div class="grid">
                <div class="fact"><strong>Operation ID</strong><span id="operation-id">—</span></div>
                <div class="fact"><strong>Auditoria</strong><span id="audit-id">—</span></div>
                <div class="fact"><strong>Reconciliação</strong><span id="reconciliation">Aguardando</span></div>
            </div>
        </section>
    </main>
    <script>
    (() => {
        const csrf = <?= json_encode($csrf, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
        const endpoints = {
            status: '/api/admin/marina-maintenance-status.php',
            preview: '/api/admin/marina-maintenance-preview.php',
            approve: '/api/admin/marina-maintenance-approve.php',
            operation: '/api/admin/marina-maintenance-operation.php?operation_id='
        };
        const $ = (id) => document.getElementById(id);
        let state = '';
        let operationId = '';
        const stateClass = (value) => value === 'SUCCEEDED_RECONCILED' ? 'good' : (value === 'NAO_APTO' || value === 'REFUSED' || value === 'INDETERMINATE_INSPECTION_REQUIRED' ? 'bad' : 'warn');
        const display = (value) => value === undefined || value === null || value === '' ? '—' : String(value);
        function setMessage(text, danger = false) { $('message').textContent = text; $('message').className = 'notice' + (danger ? ' danger-note' : ''); }
        function updateApproval() {
            let validTicket = false;
            try { const ticket = JSON.parse($('ticket').value); validTicket = ticket && typeof ticket === 'object' && !Array.isArray(ticket); } catch (_) {}
            $('approve').disabled = !(state === 'APPROVAL_REQUIRED' && validTicket && $('signature').value.trim().length >= 16);
        }
        function render(data) {
            state = display(data.state);
            $('state').textContent = state;
            $('state').className = 'state ' + stateClass(state);
            $('broker').textContent = display(data.code || (state === 'NAO_APTO' ? 'Broker não provisionado' : 'Disponível'));
            $('operation-id').textContent = display(data.operation_id || operationId);
            $('audit-id').textContent = display(data.audit_id);
            $('reconciliation').textContent = state === 'SUCCEEDED_RECONCILED' ? 'Confirmada pelo broker oficial' : state;
            if (state === 'NAO_APTO') setMessage('Não apto: o broker privado oficial ainda não está provisionado. Nenhuma mutação foi tentada.', true);
            else if (state === 'REFUSED') setMessage('Operação recusada pelo controle de autorização: ' + display(data.code), true);
            else if (state === 'INDETERMINATE_INSPECTION_REQUIRED') setMessage('Inspeção necessária: o resultado do broker não pode ser confirmado. Não repetir aprovação automaticamente.', true);
            else if (state === 'SUCCEEDED_RECONCILED') setMessage('Operação concluída e reconciliada pelo broker oficial.');
            updateApproval();
        }
        async function call(url, options = {}) {
            const response = await fetch(url, { credentials: 'same-origin', cache: 'no-store', ...options });
            const data = await response.json().catch(() => ({ state: 'UNKNOWN', code: 'invalid_json' }));
            if (!response.ok && !data.state) throw new Error('HTTP ' + response.status);
            return data;
        }
        async function refresh() {
            try { render(await call(endpoints.status)); }
            catch (error) { state = 'UNKNOWN'; render({ state, code: 'status_unavailable' }); setMessage('Não foi possível ler o estado com segurança.', true); }
        }
        $('refresh').addEventListener('click', refresh);
        $('preview').addEventListener('click', async () => {
            try { render(await call(endpoints.preview, { method: 'POST', headers: { 'X-CSRF-Token': csrf, 'Content-Type': 'application/json' }, body: '{}' })); }
            catch (_) { setMessage('Pré-verificação indisponível; nenhuma ação foi executada.', true); }
        });
        $('ticket').addEventListener('input', updateApproval);
        $('signature').addEventListener('input', updateApproval);
        $('approve').addEventListener('click', async () => {
            if ($('approve').disabled) return;
            let ticket;
            try { ticket = JSON.parse($('ticket').value); } catch (_) { setMessage('Ticket JSON inválido.', true); return; }
            $('approve').disabled = true;
            try {
                const result = await call(endpoints.approve, { method: 'POST', headers: { 'X-CSRF-Token': csrf, 'Content-Type': 'application/json' }, body: JSON.stringify({ ticket, signature_b64: $('signature').value.trim() }) });
                operationId = result.operation_id || '';
                render(result);
                if (operationId && result.state === 'RUNNING') {
                    const poll = async () => { const current = await call(endpoints.operation + encodeURIComponent(operationId)); render(current); if (current.state === 'RUNNING') setTimeout(poll, 3000); };
                    setTimeout(poll, 1500);
                }
            } catch (_) { setMessage('Aprovação não confirmada; inspecione o estado antes de repetir.', true); }
            updateApproval();
        });
        refresh();
    })();
    </script>
</body>
</html>

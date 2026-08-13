(() => {
  'use strict';

  if (location.pathname.replace(/\/+$/, '') !== '/admin/ai-image-studio/admin_dashboard.php') return;

  const STORAGE_KEY = 'sv:image-studio:professional-ops:v1';
  const MAX_JOBS = 300;
  const MAX_AGE_MS = 7 * 24 * 60 * 60 * 1000;
  const HEALTH_TTL_MS = 2 * 60 * 1000;
  const QUEUE_SLA_MS = 5 * 60 * 1000;
  const RUN_SLA_MS = 12 * 60 * 1000;
  const POLL_FALLBACK_MS = 30 * 1000;
  const labels = {openai:'OpenAI',google:'Gemini',claude:'Claude',openrouter:'OpenRouter',groq:'Groq'};
  const typeLabels = {cover:'Capa',white:'Branco técnico',hero:'Hero',ambient:'Ambientada'};
  const $ = (selector, root = document) => root.querySelector(selector);
  const $$ = (selector, root = document) => [...root.querySelectorAll(selector)];
  const esc = (value) => String(value ?? '').replace(/[&<>"']/g, (char) => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[char]));
  const nowIso = () => new Date().toISOString();

  let health = {checked_at:0, providers:{}, has_visual_editor:false, error:''};
  let lastStatusSeenAt = 0;
  let bypassPreflightOnce = false;
  let refreshTimer = 0;
  let root = null;
  let banner = null;

  function emptyLedger() {
    return {version:1, active_batch_id:'', batches:{}, jobs:{}, updated_at:nowIso()};
  }

  function loadLedger() {
    try {
      const raw = localStorage.getItem(STORAGE_KEY);
      const parsed = raw ? JSON.parse(raw) : emptyLedger();
      if (!parsed || typeof parsed !== 'object') return emptyLedger();
      parsed.batches = parsed.batches && typeof parsed.batches === 'object' ? parsed.batches : {};
      parsed.jobs = parsed.jobs && typeof parsed.jobs === 'object' ? parsed.jobs : {};
      const cutoff = Date.now() - MAX_AGE_MS;
      for (const [jobId, job] of Object.entries(parsed.jobs)) {
        const stamp = Date.parse(job.updated_at || job.enqueued_at || '') || 0;
        if (stamp && stamp < cutoff) delete parsed.jobs[jobId];
      }
      for (const [batchId, batch] of Object.entries(parsed.batches)) {
        const stamp = Date.parse(batch.updated_at || batch.created_at || '') || 0;
        if (stamp && stamp < cutoff) delete parsed.batches[batchId];
      }
      return parsed;
    } catch (_) {
      return emptyLedger();
    }
  }

  let ledger = loadLedger();

  function saveLedger() {
    ledger.updated_at = nowIso();
    const jobs = Object.values(ledger.jobs)
      .sort((a,b) => (Date.parse(b.updated_at || b.enqueued_at || '') || 0) - (Date.parse(a.updated_at || a.enqueued_at || '') || 0))
      .slice(0, MAX_JOBS);
    ledger.jobs = Object.fromEntries(jobs.map((job) => [String(job.job_id), job]));
    const usedBatches = new Set(jobs.map((job) => job.batch_id).filter(Boolean));
    ledger.batches = Object.fromEntries(Object.entries(ledger.batches).filter(([id]) => usedBatches.has(id) || id === ledger.active_batch_id));
    try { localStorage.setItem(STORAGE_KEY, JSON.stringify(ledger)); } catch (_) {}
    scheduleRender();
  }

  function newBatch(reason = 'generation') {
    const id = `IMG-${new Date().toISOString().replace(/[-:TZ.]/g,'').slice(0,14)}-${Math.random().toString(36).slice(2,7).toUpperCase()}`;
    ledger.active_batch_id = id;
    ledger.batches[id] = {id, reason, created_at:nowIso(), updated_at:nowIso(), last_enqueue_at:nowIso()};
    return id;
  }

  function ensureBatch() {
    const current = ledger.batches[ledger.active_batch_id];
    const last = Date.parse(current?.last_enqueue_at || '') || 0;
    if (!current || Date.now() - last > 90 * 1000) return newBatch('generation');
    current.last_enqueue_at = nowIso();
    current.updated_at = nowIso();
    return current.id;
  }

  function markBatchEnqueue(batchId) {
    const batch = ledger.batches[batchId];
    if (!batch) return;
    batch.last_enqueue_at = nowIso();
    batch.updated_at = nowIso();
  }

  function terminal(job) {
    return Boolean(job?.terminal) || ['done','failed'].includes(String(job?.status || '').toLowerCase());
  }

  function issue(job) {
    return ['failed','partial_failure','done_without_visible_staging'].includes(String(job?.result_state || '').toLowerCase());
  }

  function nonTerminalJobs() {
    return Object.values(ledger.jobs).filter((job) => Number(job.job_id) > 0 && !terminal(job));
  }

  function retryTypes(job) {
    const failed = Array.isArray(job.failed_types) ? job.failed_types : [];
    const missing = Array.isArray(job.missing_types) ? job.missing_types : [];
    const narrowed = [...new Set([...failed, ...missing].filter((type) => typeLabels[type]))];
    return narrowed.length ? narrowed : [...new Set((job.image_types || []).filter((type) => typeLabels[type]))];
  }

  function formatDuration(ms) {
    if (!Number.isFinite(ms) || ms < 0) return '—';
    const seconds = Math.floor(ms / 1000);
    if (seconds < 60) return `${seconds}s`;
    const minutes = Math.floor(seconds / 60);
    if (minutes < 60) return `${minutes}m ${seconds % 60}s`;
    const hours = Math.floor(minutes / 60);
    return `${hours}h ${minutes % 60}m`;
  }

  function jobAge(job) {
    const created = Date.parse(job.created_at || job.enqueued_at || '') || Date.now();
    const started = Date.parse(job.started_at || '') || 0;
    const finished = Date.parse(job.finished_at || '') || 0;
    if (terminal(job) && finished) return Math.max(0, finished - (started || created));
    if (String(job.status) === 'running' && started) return Math.max(0, Date.now() - started);
    return Math.max(0, Date.now() - created);
  }

  function slaState(job) {
    if (terminal(job)) return '';
    const age = jobAge(job);
    if (String(job.status) === 'running' && age > RUN_SLA_MS) return 'running_sla';
    if (String(job.status) !== 'running' && age > QUEUE_SLA_MS) return 'queue_sla';
    return '';
  }

  function showBanner(message, kind = 'info') {
    if (!banner) return;
    banner.className = `iv-ops-banner ${kind}`;
    banner.textContent = message;
    banner.hidden = false;
  }

  function clearBanner() {
    if (banner) banner.hidden = true;
  }

  function selectedProvider() {
    return String($('#iv-provider')?.value || '').toLowerCase();
  }

  function providerViability(provider) {
    const info = health.providers?.[provider];
    if (!info) return {ok:false, hard:true, reason:'O provedor ainda não foi validado.'};
    const working = Number(info.working_key_count || 0);
    if (working < 1) return {ok:false, hard:true, reason:info.detail || 'Nenhuma credencial autenticou.'};
    if (['claude','groq'].includes(provider) && !health.has_visual_editor) {
      return {ok:false, hard:true, reason:'O otimizador autenticou, mas nenhum editor visual está apto a concluir os pixels.'};
    }
    if (info.ok === false) return {ok:true, hard:false, warning:info.detail || 'Há alerta operacional recente para este provedor.'};
    return {ok:true, hard:false, reason:info.detail || 'Pré-flight aprovado.'};
  }

  async function refreshHealth(force = false) {
    if (!force && health.checked_at && Date.now() - health.checked_at < HEALTH_TTL_MS) return health;
    try {
      const response = await fetch('/admin/ai-image-studio/api/provider_health_check.php', {credentials:'same-origin', headers:{Accept:'application/json'}});
      if (response.redirected || /\/auth\/login\.php/i.test(response.url || '')) throw new Error('Sessão administrativa expirada.');
      const data = await response.json();
      if (!response.ok || data?.success === false) throw new Error(data?.error || `Falha HTTP ${response.status}.`);
      health = {checked_at:Date.now(), providers:data.providers || {}, has_visual_editor:Boolean(data.has_visual_editor), error:''};
      renderHealth();
      return health;
    } catch (error) {
      health = {checked_at:Date.now(), providers:{}, has_visual_editor:false, error:String(error?.message || error)};
      renderHealth();
      throw error;
    }
  }

  function renderHealth() {
    const host = $('#iv-ops-health');
    if (!host) return;
    if (health.error) {
      host.innerHTML = `<span class="iv-ops-chip bad">Pré-flight indisponível: ${esc(health.error)}</span>`;
      return;
    }
    const entries = Object.entries(health.providers || {});
    host.innerHTML = entries.map(([provider, info]) => {
      const viability = providerViability(provider);
      const klass = viability.hard ? 'bad' : info.ok === false ? 'warn' : 'good';
      const text = viability.hard ? 'bloqueado' : info.ok === false ? 'atenção' : 'apto';
      return `<span class="iv-ops-chip ${klass}" title="${esc(info.detail || '')}">${esc(labels[provider] || provider)} · ${text}</span>`;
    }).join('') || '<span class="iv-ops-chip warn">Pré-flight ainda não executado.</span>';
  }

  function normalizeStatusJob(job) {
    const id = Number(job.job_id || job.id || 0);
    if (!id) return;
    const existing = ledger.jobs[String(id)] || {job_id:id, batch_id:ledger.active_batch_id || '', enqueued_at:nowIso()};
    const variants = job.variants || {};
    ledger.jobs[String(id)] = {
      ...existing,
      job_id:id,
      product_id:Number(job.product_id || existing.product_id || 0),
      provider:String(job.provider || existing.provider || ''),
      target_channel:String(job.target_channel || existing.target_channel || ''),
      image_types:Array.isArray(job.image_types) ? job.image_types : (existing.image_types || []),
      status:String(job.status || existing.status || 'unknown'),
      result_state:String(job.result_state || existing.result_state || 'unknown'),
      terminal:Boolean(job.terminal),
      attempts:Number(job.attempts || existing.attempts || 0),
      created_at:String(job.created_at || existing.created_at || existing.enqueued_at || ''),
      started_at:job.started_at || existing.started_at || null,
      finished_at:job.finished_at || existing.finished_at || null,
      failed_types:Array.isArray(variants.failed_types) ? variants.failed_types : (existing.failed_types || []),
      missing_types:Array.isArray(variants.missing) ? variants.missing : (existing.missing_types || []),
      ready_types:Array.isArray(variants.ready_types) ? variants.ready_types : (existing.ready_types || []),
      last_error:String(job.last_error || existing.last_error || ''),
      updated_at:nowIso(),
    };
  }

  function ingestStatus(data) {
    if (!data || !Array.isArray(data.jobs)) return;
    lastStatusSeenAt = Date.now();
    data.jobs.forEach(normalizeStatusJob);
    saveLedger();
  }

  function recordEnqueue(request, response) {
    const jobId = Number(response?.job_id || 0);
    const productId = Number(response?.product_id || request?.product_id || 0);
    if (!jobId || !productId) return;
    const batchId = ensureBatch();
    markBatchEnqueue(batchId);
    ledger.jobs[String(jobId)] = {
      ...(ledger.jobs[String(jobId)] || {}),
      job_id:jobId,
      batch_id:batchId,
      product_id:productId,
      provider:String(response?.provider_requested || request?.provider || ''),
      target_channel:String(response?.target_channel || request?.target_channel || ''),
      image_types:Array.isArray(response?.image_types) ? response.image_types : (Array.isArray(request?.image_types) ? request.image_types : []),
      model:String(request?.model || request?.model_override || ''),
      status:String(response?.job_status || 'queued'),
      result_state:'queued',
      terminal:false,
      deduplicated:Boolean(response?.deduplicated),
      enqueued_at:nowIso(),
      updated_at:nowIso(),
      last_error:'',
    };
    saveLedger();
  }

  const previousFetch = window.fetch.bind(window);
  window.fetch = async (input, init = {}) => {
    let url;
    try {
      const raw = typeof input === 'string' ? input : input?.url;
      url = new URL(raw, location.origin);
    } catch (_) {
      return previousFetch(input, init);
    }
    const sameOrigin = url.origin === location.origin;
    const isEnqueue = sameOrigin && /\/admin\/ai-image-studio\/api\/enqueue_generation\.php$/i.test(url.pathname);
    const isStatus = sameOrigin && /\/admin\/ai-image-studio\/api\/generation_status\.php$/i.test(url.pathname);
    let requestPayload = null;
    if (isEnqueue && typeof init?.body === 'string') {
      try { requestPayload = JSON.parse(init.body); } catch (_) {}
    }
    const response = await previousFetch(input, init);
    if (isEnqueue || isStatus) {
      try {
        const payload = await response.clone().json();
        if (isEnqueue && response.ok && payload?.success !== false) recordEnqueue(requestPayload || {}, payload);
        if (isStatus && response.ok && payload?.success !== false) ingestStatus(payload);
      } catch (_) {}
    }
    return response;
  };

  function statusClass(job) {
    if (issue(job)) return 'bad';
    if (job.result_state === 'ready_for_review') return 'good';
    if (job.status === 'running') return 'warn';
    if (terminal(job)) return 'good';
    return '';
  }

  function statusText(job) {
    if (job.result_state === 'ready_for_review') return 'Pronto para revisão';
    if (job.result_state === 'partial_failure') return 'Parcial';
    if (job.result_state === 'failed' || job.status === 'failed') return 'Falhou';
    if (job.status === 'running') return 'Gerando';
    if (job.status === 'queued') return 'Na fila';
    if (terminal(job)) return 'Concluído';
    return String(job.status || 'desconhecido');
  }

  function batchJobs() {
    const active = ledger.active_batch_id;
    let jobs = Object.values(ledger.jobs).filter((job) => active && job.batch_id === active);
    if (!jobs.length) jobs = Object.values(ledger.jobs).sort((a,b) => (Date.parse(b.updated_at || '') || 0) - (Date.parse(a.updated_at || '') || 0)).slice(0,30);
    return jobs.sort((a,b) => Number(a.job_id) - Number(b.job_id));
  }

  function render() {
    if (!root) return;
    const jobs = batchJobs();
    const total = jobs.length;
    const ready = jobs.filter((job) => job.result_state === 'ready_for_review').length;
    const running = jobs.filter((job) => !terminal(job)).length;
    const issues = jobs.filter(issue).length;
    const sla = jobs.filter((job) => slaState(job)).length;
    const completed = jobs.filter(terminal).length;
    const pct = total ? Math.round((completed / total) * 100) : 0;
    $('#iv-ops-batch').textContent = ledger.active_batch_id || 'sem lote ativo';
    $('#iv-ops-total').textContent = String(total);
    $('#iv-ops-ready').textContent = String(ready);
    $('#iv-ops-running').textContent = String(running);
    $('#iv-ops-issues').textContent = String(issues);
    $('#iv-ops-sla').textContent = String(sla);
    $('#iv-ops-progress-bar').style.width = `${pct}%`;
    $('#iv-ops-progress-label').textContent = `${completed}/${total || 0} terminal(is) · ${pct}%`;
    const retry = $('#iv-ops-retry');
    if (retry) retry.disabled = !jobs.some((job) => terminal(job) && issue(job));
    const list = $('#iv-ops-list');
    if (!list) return;
    if (!jobs.length) {
      list.innerHTML = '<div class="iv-ops-empty">Nenhum job acompanhado neste navegador. O próximo lote será registrado automaticamente.</div>';
      return;
    }
    list.innerHTML = jobs.map((job) => {
      const slaCode = slaState(job);
      const types = (job.image_types || []).map((type) => typeLabels[type] || type).join(', ');
      const retry = retryTypes(job).map((type) => typeLabels[type] || type).join(', ');
      const error = job.last_error ? `<div class="iv-ops-error">${esc(job.last_error)}</div>` : '';
      const audit = job.retry_of ? ` · retry de #${Number(job.retry_of)}` : job.deduplicated ? ' · fila equivalente reutilizada' : '';
      const slaNote = slaCode === 'queue_sla' ? '<span class="iv-ops-chip warn">SLA fila &gt; 5m</span>' : slaCode === 'running_sla' ? '<span class="iv-ops-chip warn">SLA geração &gt; 12m</span>' : '';
      return `<details class="iv-ops-job"><summary><span>#${Number(job.job_id)} · produto #${Number(job.product_id || 0)}</span><span>${esc(labels[job.provider] || job.provider || 'IA')} · ${esc(job.target_channel || '')}</span><span class="iv-ops-chip ${statusClass(job)}">${esc(statusText(job))}</span><span>${formatDuration(jobAge(job))}</span></summary><div class="iv-ops-job-body"><div><strong>Variantes:</strong> ${esc(types || '—')}</div><div><strong>Nova tentativa usaria:</strong> ${esc(retry || '—')}</div><div><strong>Tentativas do worker:</strong> ${Number(job.attempts || 0)}${esc(audit)}</div>${slaNote}${error}</div></details>`;
    }).join('');
  }

  function scheduleRender() {
    if (refreshTimer) return;
    refreshTimer = window.setTimeout(() => { refreshTimer = 0; render(); }, 50);
  }

  async function refreshTrackedJobs(force = false) {
    const jobs = nonTerminalJobs();
    if (!jobs.length) return;
    if (!force && Date.now() - lastStatusSeenAt < 25 * 1000) return;
    const ids = [...new Set(jobs.map((job) => Number(job.job_id)).filter(Boolean))];
    try {
      for (let offset = 0; offset < ids.length; offset += 100) {
        const chunk = ids.slice(offset, offset + 100);
        const response = await fetch(`/admin/ai-image-studio/api/generation_status.php?job_ids=${encodeURIComponent(chunk.join(','))}`, {credentials:'same-origin', headers:{Accept:'application/json'}});
        if (response.redirected || /\/auth\/login\.php/i.test(response.url || '')) throw new Error('Sessão administrativa expirada.');
        const data = await response.json();
        if (!response.ok || data?.success === false) throw new Error(data?.error || `Falha HTTP ${response.status}.`);
        ingestStatus(data);
      }
      clearBanner();
    } catch (error) {
      showBanner(`Monitoramento operacional indisponível: ${error.message}`, 'warn');
    }
  }

  async function retryFailed() {
    const failed = batchJobs().filter((job) => terminal(job) && issue(job));
    if (!failed.length) return;
    const newBatchId = newBatch('retry');
    showBanner(`Reenfileirando somente variantes falhas/ausentes de ${failed.length} job(s)...`, 'info');
    let ok = 0;
    let fail = 0;
    for (const job of failed) {
      const types = retryTypes(job);
      if (!types.length) { fail++; continue; }
      try {
        const response = await fetch('/admin/ai-image-studio/api/enqueue_generation.php', {
          method:'POST', credentials:'same-origin', headers:{'Content-Type':'application/json','Accept':'application/json'},
          body:JSON.stringify({product_id:Number(job.product_id),provider:String(job.provider || selectedProvider()),model:String(job.model || ''),target_channel:String(job.target_channel || $('#iv-channel')?.value || 'site'),image_types:types})
        });
        const data = await response.json();
        if (!response.ok || data?.success === false) throw new Error(data?.error || `Falha HTTP ${response.status}.`);
        const nextId = Number(data.job_id || 0);
        if (nextId && ledger.jobs[String(nextId)]) {
          ledger.jobs[String(nextId)].batch_id = newBatchId;
          ledger.jobs[String(nextId)].retry_of = Number(job.job_id);
          ledger.jobs[String(nextId)].updated_at = nowIso();
        }
        ok++;
      } catch (error) {
        fail++;
        job.last_retry_error = String(error?.message || error);
        job.updated_at = nowIso();
      }
    }
    saveLedger();
    showBanner(fail ? `Retry concluído: ${ok} reenfileirado(s), ${fail} não reenfileirado(s).` : `Retry concluído: ${ok} job(s) reenfileirado(s) com escopo reduzido.`, fail ? 'warn' : 'good');
    await refreshTrackedJobs(true);
  }

  function clearCompleted() {
    for (const [id, job] of Object.entries(ledger.jobs)) if (terminal(job)) delete ledger.jobs[id];
    const remaining = Object.values(ledger.jobs);
    ledger.active_batch_id = remaining.at(-1)?.batch_id || '';
    saveLedger();
    showBanner('Jobs concluídos foram removidos do painel local. O staging e a fila do servidor não foram alterados.', 'good');
  }

  function exportReport() {
    const payload = {
      schema:'shopvivaliz.ai-image-ops.v1',
      exported_at:nowIso(),
      active_batch_id:ledger.active_batch_id || null,
      provider_health:{checked_at:health.checked_at ? new Date(health.checked_at).toISOString() : null, has_visual_editor:health.has_visual_editor, providers:Object.fromEntries(Object.entries(health.providers || {}).map(([key,info]) => [key,{ok:Boolean(info.ok),working_key_count:Number(info.working_key_count || 0),key_count:Number(info.key_count || 0),detail:String(info.detail || '')}]))},
      jobs:batchJobs().map((job) => ({...job})),
    };
    const blob = new Blob([JSON.stringify(payload, null, 2)], {type:'application/json'});
    const url = URL.createObjectURL(blob);
    const anchor = document.createElement('a');
    anchor.href = url;
    anchor.download = `shopvivaliz-image-ops-${(ledger.active_batch_id || 'report').toLowerCase()}.json`;
    document.body.appendChild(anchor);
    anchor.click();
    anchor.remove();
    setTimeout(() => URL.revokeObjectURL(url), 1000);
  }

  async function preflightBeforeRun(event) {
    const button = event.target instanceof Element ? event.target.closest('#iv-run') : null;
    if (!button) return;
    if (bypassPreflightOnce) { bypassPreflightOnce = false; return; }
    const provider = selectedProvider();
    if (!provider) return;
    const stale = !health.checked_at || Date.now() - health.checked_at >= HEALTH_TTL_MS;
    if (stale) {
      event.preventDefault();
      event.stopImmediatePropagation();
      showBanner('Executando pré-flight obrigatório antes de criar o lote...', 'info');
      try {
        await refreshHealth(true);
        const viability = providerViability(selectedProvider());
        if (viability.hard) {
          showBanner(`Lote não enfileirado: ${viability.reason}`, 'bad');
          return;
        }
        if (viability.warning) showBanner(`Pré-flight com alerta: ${viability.warning}`, 'warn');
        else showBanner('Pré-flight aprovado. Enfileirando o lote selecionado...', 'good');
        newBatch('generation');
        bypassPreflightOnce = true;
        button.click();
      } catch (error) {
        showBanner(`Lote não enfileirado porque o pré-flight falhou: ${error.message}`, 'bad');
      }
      return;
    }
    const viability = providerViability(provider);
    if (viability.hard) {
      event.preventDefault();
      event.stopImmediatePropagation();
      showBanner(`Lote não enfileirado: ${viability.reason}`, 'bad');
      return;
    }
    newBatch('generation');
    if (viability.warning) showBanner(`Pré-flight com alerta: ${viability.warning}`, 'warn');
    else showBanner('Pré-flight válido. Lote liberado para a fila.', 'good');
  }

  function install() {
    const generation = $('#iv-generation');
    if (!generation || $('#iv-professional-ops')) return false;
    const style = document.createElement('style');
    style.textContent = `
      .iv-ops{margin:14px 0;border:1px solid #cbd5e1;border-radius:14px;background:#fff;box-shadow:0 8px 26px rgba(15,23,42,.05);overflow:hidden}.iv-ops-head{display:flex;justify-content:space-between;gap:12px;align-items:flex-start;padding:16px 18px;border-bottom:1px solid #e2e8f0}.iv-ops-head h2{margin:0 0 4px}.iv-ops-head p{margin:0;color:#64748b}.iv-ops-head code{font-size:11px}.iv-ops-health{display:flex;gap:6px;flex-wrap:wrap;padding:11px 18px;border-bottom:1px solid #e2e8f0;background:#f8fafc}.iv-ops-chip{display:inline-flex;align-items:center;border-radius:999px;padding:4px 8px;background:#eef2f7;color:#475569;font-size:11px;font-weight:850}.iv-ops-chip.good{background:#dcfce7;color:#166534}.iv-ops-chip.warn{background:#fef3c7;color:#854d0e}.iv-ops-chip.bad{background:#fee2e2;color:#991b1b}.iv-ops-banner{margin:12px 18px 0;padding:9px 11px;border-radius:9px;background:#eff6ff;color:#075985;font-weight:750}.iv-ops-banner.good{background:#ecfdf3;color:#166534}.iv-ops-banner.warn{background:#fffbeb;color:#854d0e}.iv-ops-banner.bad{background:#fff1f1;color:#991b1b}.iv-ops-metrics{display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:8px;padding:12px 18px}.iv-ops-metric{padding:11px;border:1px solid #e2e8f0;border-radius:10px;background:#fff}.iv-ops-metric strong{display:block;font-size:22px}.iv-ops-metric span{font-size:11px;color:#64748b;text-transform:uppercase;font-weight:800}.iv-ops-progress{margin:0 18px 12px}.iv-ops-track{height:8px;border-radius:999px;background:#e2e8f0;overflow:hidden}.iv-ops-track>div{height:100%;width:0;background:#1769aa;transition:width .2s}.iv-ops-progress small{display:block;margin-top:5px;color:#64748b}.iv-ops-actions{display:flex;gap:7px;flex-wrap:wrap;padding:0 18px 12px}.iv-ops-actions button{border:0;border-radius:9px;padding:8px 10px;font-weight:800;cursor:pointer;background:#eef2f7;color:#334155}.iv-ops-actions button.primary{background:#1769aa;color:#fff}.iv-ops-actions button:disabled{opacity:.45;cursor:not-allowed}.iv-ops-list{border-top:1px solid #e2e8f0;max-height:340px;overflow:auto}.iv-ops-job{border-bottom:1px solid #edf2f7}.iv-ops-job>summary{display:grid;grid-template-columns:1.2fr 1fr auto auto;gap:8px;align-items:center;padding:9px 18px;cursor:pointer}.iv-ops-job-body{padding:0 18px 11px;color:#475569;font-size:12px}.iv-ops-job-body>div{margin:4px 0}.iv-ops-error{padding:7px 9px!important;border-radius:7px;background:#fff1f1;color:#991b1b;overflow-wrap:anywhere}.iv-ops-empty{padding:18px;text-align:center;color:#64748b}.iv-ops-foot{padding:9px 18px;background:#f8fafc;color:#64748b;font-size:11px;border-top:1px solid #e2e8f0}@media(max-width:820px){.iv-ops-metrics{grid-template-columns:1fr 1fr}.iv-ops-job>summary{grid-template-columns:1fr auto}.iv-ops-job>summary span:nth-child(2){grid-column:1/-1}}
    `;
    document.head.appendChild(style);
    root = document.createElement('section');
    root.id = 'iv-professional-ops';
    root.className = 'iv-ops';
    root.innerHTML = `<div class="iv-ops-head"><div><h2>Operação profissional</h2><p>Pré-flight, rastreabilidade do lote, SLA, retomada após reload e retry somente do que falhou.</p></div><div><small>Lote ativo</small><br><code id="iv-ops-batch">—</code></div></div><div id="iv-ops-health" class="iv-ops-health"></div><div id="iv-ops-banner" class="iv-ops-banner" hidden></div><div class="iv-ops-metrics"><div class="iv-ops-metric"><strong id="iv-ops-total">0</strong><span>jobs</span></div><div class="iv-ops-metric"><strong id="iv-ops-ready">0</strong><span>prontos</span></div><div class="iv-ops-metric"><strong id="iv-ops-running">0</strong><span>em andamento</span></div><div class="iv-ops-metric"><strong id="iv-ops-issues">0</strong><span>pendências</span></div><div class="iv-ops-metric"><strong id="iv-ops-sla">0</strong><span>alertas SLA</span></div></div><div class="iv-ops-progress"><div class="iv-ops-track"><div id="iv-ops-progress-bar"></div></div><small id="iv-ops-progress-label">0/0 terminal(is)</small></div><div class="iv-ops-actions"><button id="iv-ops-health-refresh">Atualizar pré-flight</button><button id="iv-ops-resume">Retomar monitoramento</button><button id="iv-ops-retry" class="primary" disabled>Reenfileirar falhas</button><button id="iv-ops-export">Exportar relatório</button><button id="iv-ops-clear">Limpar concluídos</button></div><div id="iv-ops-list" class="iv-ops-list"></div><div class="iv-ops-foot">O painel local não publica, não aprova imagens e não altera staging. Publicação continua exigindo revisão visual humana explícita.</div>`;
    generation.insertAdjacentElement('afterend', root);
    banner = $('#iv-ops-banner');
    $('#iv-ops-health-refresh').addEventListener('click', async () => { try { await refreshHealth(true); showBanner('Pré-flight atualizado.', 'good'); } catch (error) { showBanner(`Pré-flight falhou: ${error.message}`, 'bad'); } });
    $('#iv-ops-resume').addEventListener('click', () => refreshTrackedJobs(true));
    $('#iv-ops-retry').addEventListener('click', retryFailed);
    $('#iv-ops-export').addEventListener('click', exportReport);
    $('#iv-ops-clear').addEventListener('click', clearCompleted);
    render();
    renderHealth();
    setTimeout(() => refreshHealth(false).catch(() => {}), 700);
    setTimeout(() => refreshTrackedJobs(true), 1200);
    return true;
  }

  document.addEventListener('click', preflightBeforeRun, true);
  document.addEventListener('visibilitychange', () => {
    if (document.visibilityState !== 'visible') return;
    refreshHealth(false).catch(() => {});
    refreshTrackedJobs(false);
  });
  setInterval(() => {
    if (document.visibilityState !== 'visible') return;
    refreshTrackedJobs(false);
    render();
  }, POLL_FALLBACK_MS);

  if (!install()) {
    const observer = new MutationObserver(() => { if (install()) observer.disconnect(); });
    observer.observe(document.documentElement, {childList:true, subtree:true});
    setTimeout(() => observer.disconnect(), 10000);
  }
})();

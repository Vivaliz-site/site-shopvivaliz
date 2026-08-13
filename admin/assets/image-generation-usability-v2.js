(() => {
  'use strict';

  const page = location.pathname.replace(/\/+$/, '');
  if (page !== '/admin/ai-image-studio/admin_dashboard.php') return;

  const $ = (selector, root = document) => root.querySelector(selector);
  const $$ = (selector, root = document) => [...root.querySelectorAll(selector)];
  const TYPES = { cover: 'Capa', white: 'Branco tecnico', hero: 'Hero', ambient: 'Ambientada' };
  const TYPE_BY_LABEL = {
    capa: 'cover', 'capa do canal': 'cover', cover: 'cover',
    branco: 'white', 'branco tecnico': 'white', white: 'white',
    hero: 'hero', 'hero comercial': 'hero',
    ambientada: 'ambient', lifestyle: 'ambient', 'ambientada lifestyle': 'ambient'
  };
  const JOB_KEY = 'svImageJobsV3';
  const DRAFT_PREFIX = 'svImageDraftV3:';
  let applyingDraft = false;
  let preflightApproved = false;
  let initialized = false;
  const jobs = new Map();

  const style = document.createElement('style');
  style.id = 'sv-image-usability-v2-style';
  style.textContent = `
    :root{--sv-img-purple:#7e22ce;--sv-img-purple-dark:#581c87;--sv-img-purple-bg:#faf5ff;--sv-img-purple-line:#d8b4fe}
    .sv-img-resume{margin:0 19px 12px;padding:10px 12px;border:1px solid var(--sv-img-purple-line);border-radius:10px;background:var(--sv-img-purple-bg);color:var(--sv-img-purple-dark);display:none;align-items:center;justify-content:space-between;gap:9px;flex-wrap:wrap}
    .sv-img-resume.show{display:flex}.sv-img-resume strong{display:block}.sv-img-resume small{display:block;color:#6b21a8;margin-top:2px}
    .sv-img-resume button,.sv-retry-missing,.sv-copy-error,.sv-img-clear-draft{border:0;border-radius:8px;padding:8px 10px;font:inherit;font-size:12px;font-weight:850;cursor:pointer;background:#f3e8ff;color:var(--sv-img-purple-dark)}
    .sv-retry-missing{background:var(--sv-img-purple);color:#fff;margin-left:7px}.sv-copy-error{background:#fee2e2;color:#991b1b;margin-left:6px}
    .sv-img-preflight{position:fixed;inset:0;z-index:10050;display:none;place-items:center;padding:16px;background:rgba(15,23,42,.62);backdrop-filter:blur(4px)}
    .sv-img-preflight.show{display:grid}.sv-img-preflight-card{width:min(560px,100%);max-height:min(82vh,720px);overflow:auto;background:#fff;border-radius:15px;box-shadow:0 24px 70px rgba(15,23,42,.32);padding:18px;color:#0f172a}
    .sv-img-preflight-card h2{margin:0 0 5px;font-size:22px}.sv-img-preflight-card>p{margin:0 0 13px;color:#64748b}
    .sv-img-preflight-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:8px;margin:12px 0}.sv-img-preflight-stat{border:1px solid #e2e8f0;border-radius:10px;padding:10px;background:#f8fafc}.sv-img-preflight-stat strong{display:block;font-size:20px;color:var(--sv-img-purple-dark)}.sv-img-preflight-stat span{font-size:12px;color:#64748b}
    .sv-img-preflight-list{display:grid;gap:6px;max-height:230px;overflow:auto;margin:10px 0;padding:0}.sv-img-preflight-row{display:flex;justify-content:space-between;gap:8px;border:1px solid #e2e8f0;border-radius:9px;padding:8px 9px;font-size:13px}.sv-img-preflight-row span:last-child{color:#6b21a8;text-align:right}
    .sv-img-preflight-warning{padding:9px 10px;border-radius:9px;background:#fff8e1;border:1px solid #f4dda0;color:#6b5300;font-size:13px;margin:9px 0}
    .sv-img-preflight-actions{display:flex;justify-content:flex-end;gap:8px;margin-top:14px}.sv-img-preflight-actions button{border:0;border-radius:9px;padding:11px 14px;font:inherit;font-weight:900;cursor:pointer}.sv-img-cancel{background:#eef2f7;color:#334155}.sv-img-confirm{background:var(--sv-img-purple);color:#fff}
    .sv-img-route{display:block;color:#6b21a8!important;margin-top:3px!important}.sv-img-progress-actions{display:inline-flex;gap:5px;align-items:center;flex-wrap:wrap;justify-content:flex-end}
    #iv-intel .sv-img-intel-toggle{display:none;border:0;border-radius:8px;padding:7px 9px;background:#f3e8ff;color:var(--sv-img-purple-dark);font-weight:850;cursor:pointer;margin-top:7px}
    .iv-actionbar .sv-img-clear-draft{background:#fff;border:1px solid var(--sv-img-purple-line)}
    @media(max-width:720px){
      .ais-wrap,main.wrap{max-width:100%!important;margin:7px auto 112px!important;padding:0 7px!important}
      .ais-topbar{padding:10px!important;gap:7px!important}.ais-topbar h1{font-size:21px!important}.iv-sub{font-size:13px;margin:4px 4px 10px!important}
      .iv-steps{display:flex!important;overflow-x:auto;scroll-snap-type:x mandatory;gap:7px!important;margin:9px 0 11px!important;padding-bottom:3px}.iv-step{flex:0 0 auto;min-width:130px;padding:8px 9px!important;font-size:12px;scroll-snap-align:start}
      .iv-panel,.iv-diagnostics,.iv-recent{border-radius:10px!important;margin:9px 0!important}.iv-head{padding:12px 11px 9px!important}.iv-head h2{font-size:18px}.iv-head p{font-size:12px;line-height:1.4}
      .iv-config{padding:10px!important;gap:9px!important}.iv-field input,.iv-field select,.iv-toolbar input{font-size:16px!important;min-height:46px!important}
      .iv-intel{padding:9px!important;font-size:12px}.iv-intel p{display:none;margin:6px 0}.iv-intel.expanded p{display:block}#iv-intel .sv-img-intel-toggle{display:inline-flex}
      .iv-toolbar{display:flex!important;overflow-x:auto;flex-wrap:nowrap!important;padding:10px 9px 8px!important;align-items:center}.iv-toolbar input{flex:0 0 min(78vw,330px)!important}.iv-toolbar .iv-btn{flex:0 0 auto;min-height:42px}
      .iv-summary{padding:0 9px 8px!important;overflow-x:auto;flex-wrap:nowrap!important}.iv-summary span{flex:0 0 auto}
      .iv-list{max-height:none!important;overflow:visible!important;padding:0 8px 12px!important;gap:7px!important}.iv-item>summary{grid-template-columns:auto 48px minmax(0,1fr)!important;padding:9px!important;gap:8px!important}.iv-thumb{width:48px!important;height:48px!important}.iv-title strong{font-size:13px}.iv-title span{font-size:11px!important}.iv-item>summary .iv-state{grid-column:2/-1!important;justify-self:start}
      .iv-body{padding:9px!important;font-size:12px}.iv-variants{display:grid!important;grid-template-columns:1fr!important}.iv-variant{min-height:58px;padding:8px!important}.iv-actions{display:grid!important;grid-template-columns:1fr 1fr;width:100%}.iv-actions .iv-btn,.iv-actions .iv-primary{min-height:44px}
      .iv-actionbar{position:sticky!important;bottom:calc(6px + env(safe-area-inset-bottom))!important;margin:0 7px 7px!important;padding:10px!important;border-color:var(--sv-img-purple-line)!important}.iv-actionbar>.iv-actions{display:grid!important;grid-template-columns:1fr 1fr!important;width:100%}.iv-actionbar>.iv-actions button{width:100%;min-height:45px}.iv-actionbar #iv-run{grid-column:1/-1;background:var(--sv-img-purple)!important}
      .iv-progress{margin:0 8px 12px!important}.iv-progress-row{grid-template-columns:minmax(0,1fr)!important;padding:9px!important}.iv-progress-row>strong{justify-self:start}.sv-img-progress-actions{justify-content:flex-start}
      .sv-img-resume{margin:0 9px 9px;padding:9px}.sv-img-preflight{padding:8px;align-items:end}.sv-img-preflight-card{border-radius:15px 15px 0 0;padding:15px 12px;max-height:90vh}.sv-img-preflight-grid{grid-template-columns:1fr 1fr}.sv-img-preflight-actions{display:grid;grid-template-columns:1fr 1fr}.sv-img-preflight-actions button{min-height:48px}
    }
  `;
  document.head.appendChild(style);

  function safeJson(value, fallback) {
    try { return JSON.parse(value); } catch (_) { return fallback; }
  }

  function jobSnapshot() {
    return [...jobs.values()].filter((job) => Date.now() - Number(job.savedAt || 0) < 48 * 60 * 60 * 1000).slice(-80);
  }

  function persistJobs() {
    try { localStorage.setItem(JOB_KEY, JSON.stringify(jobSnapshot())); } catch (_) {}
  }

  function loadJobs() {
    const saved = safeJson(localStorage.getItem(JOB_KEY) || '[]', []);
    if (!Array.isArray(saved)) return;
    saved.forEach((job) => {
      const id = Number(job.jobId || 0);
      if (id > 0 && Date.now() - Number(job.savedAt || 0) < 48 * 60 * 60 * 1000) jobs.set(id, job);
    });
  }

  function channel() { return $('#iv-channel')?.value || 'site'; }
  function provider() { return $('#iv-provider')?.value || 'openrouter'; }
  function model() { return $('#iv-model')?.value?.trim() || ''; }
  function draftKey() { return DRAFT_PREFIX + channel(); }

  function selectedRows() {
    return $$('.iv-item').map((item) => {
      const id = Number(item.dataset.productId || 0);
      const product = $('.iv-item>summary input.iv-check', item);
      const types = $$('.iv-variant input.iv-check:checked', item).map((input) => input.value).filter(Boolean);
      return { id, item, product, selected: Boolean(product?.checked), types };
    }).filter((row) => row.id > 0 && row.selected && row.types.length > 0);
  }

  function saveDraft() {
    if (applyingDraft || !$('#iv-list')) return;
    const products = {};
    $$('.iv-item').forEach((item) => {
      const id = Number(item.dataset.productId || 0);
      if (!id) return;
      const selected = Boolean($('.iv-item>summary input.iv-check', item)?.checked);
      const types = $$('.iv-variant input.iv-check:checked', item).map((input) => input.value).filter(Boolean);
      if (selected || types.length) products[id] = { selected, types };
    });
    try {
      localStorage.setItem(draftKey(), JSON.stringify({ savedAt: Date.now(), channel: channel(), provider: provider(), model: model(), products }));
    } catch (_) {}
    updateResume();
  }

  function readDraft() {
    const data = safeJson(localStorage.getItem(draftKey()) || '{}', {});
    if (!data || Date.now() - Number(data.savedAt || 0) > 48 * 60 * 60 * 1000) return null;
    return data;
  }

  function applyDraft() {
    const draft = readDraft();
    if (!draft || !draft.products || !$('#iv-list')) return;
    applyingDraft = true;
    Object.entries(draft.products).forEach(([rawId, saved]) => {
      const item = $(`.iv-item[data-product-id="${CSS.escape(rawId)}"]`);
      if (!item) return;
      const product = $('.iv-item>summary input.iv-check', item);
      if (product && !product.disabled && product.checked !== Boolean(saved.selected)) {
        product.checked = Boolean(saved.selected);
        product.dispatchEvent(new Event('change', { bubbles: true }));
      }
      const wanted = new Set(Array.isArray(saved.types) ? saved.types : []);
      $$('.iv-variant input.iv-check', item).forEach((input) => {
        if (input.disabled) return;
        const next = wanted.has(input.value);
        if (input.checked !== next) {
          input.checked = next;
          input.dispatchEvent(new Event('change', { bubbles: true }));
        }
      });
    });
    applyingDraft = false;
    updateResume();
  }

  function clearDraft() {
    try { localStorage.removeItem(draftKey()); } catch (_) {}
    updateResume();
  }

  function updateResume() {
    const box = $('#sv-img-resume');
    if (!box) return;
    const draft = readDraft();
    const count = draft?.products ? Object.values(draft.products).filter((item) => item.selected).length : 0;
    box.classList.toggle('show', count > 0);
    const label = $('[data-sv-resume-label]', box);
    if (label) label.textContent = `${count} produto${count === 1 ? '' : 's'} guardado${count === 1 ? '' : 's'} neste marketplace`;
  }

  function preflightRows() {
    return selectedRows().map((row) => {
      const title = $('.iv-title strong', row.item)?.textContent?.trim() || `Produto #${row.id}`;
      return { ...row, title };
    });
  }

  function ensurePreflight() {
    let overlay = $('#sv-img-preflight');
    if (overlay) return overlay;
    overlay = document.createElement('div');
    overlay.id = 'sv-img-preflight';
    overlay.className = 'sv-img-preflight';
    overlay.setAttribute('role', 'dialog');
    overlay.setAttribute('aria-modal', 'true');
    overlay.setAttribute('aria-labelledby', 'sv-img-preflight-title');
    overlay.innerHTML = '<div class="sv-img-preflight-card"><h2 id="sv-img-preflight-title">Confirmar lote de geracao</h2><p>Revise o custo operacional antes de criar as filas. Nenhuma imagem sera publicada automaticamente.</p><div class="sv-img-preflight-grid"><div class="sv-img-preflight-stat"><strong data-pf-products>0</strong><span>produtos</span></div><div class="sv-img-preflight-stat"><strong data-pf-images>0</strong><span>imagens solicitadas</span></div><div class="sv-img-preflight-stat"><strong data-pf-channel>—</strong><span>marketplace</span></div><div class="sv-img-preflight-stat"><strong data-pf-provider>—</strong><span>estrategia de IA</span></div></div><div data-pf-warning></div><div class="sv-img-preflight-list" data-pf-list></div><div class="sv-img-preflight-actions"><button type="button" class="sv-img-cancel">Voltar e revisar</button><button type="button" class="sv-img-confirm">Confirmar e enfileirar</button></div></div>';
    document.body.appendChild(overlay);
    $('.sv-img-cancel', overlay).addEventListener('click', () => overlay.classList.remove('show'));
    $('.sv-img-confirm', overlay).addEventListener('click', () => {
      overlay.classList.remove('show');
      preflightApproved = true;
      $('#iv-run')?.click();
    });
    overlay.addEventListener('click', (event) => { if (event.target === overlay) overlay.classList.remove('show'); });
    return overlay;
  }

  function showPreflight() {
    const rows = preflightRows();
    if (!rows.length) return;
    const images = rows.reduce((total, row) => total + row.types.length, 0);
    const overlay = ensurePreflight();
    $('[data-pf-products]', overlay).textContent = String(rows.length);
    $('[data-pf-images]', overlay).textContent = String(images);
    $('[data-pf-channel]', overlay).textContent = $('#iv-channel option:checked')?.textContent?.trim() || channel();
    $('[data-pf-provider]', overlay).textContent = $('#iv-provider option:checked')?.textContent?.trim() || provider();
    $('[data-pf-list]', overlay).innerHTML = rows.map((row) => `<div class="sv-img-preflight-row"><span>${row.title.replace(/[&<>"']/g, '')}</span><span>${row.types.map((type) => TYPES[type] || type).join(', ')}</span></div>`).join('');
    const warning = $('[data-pf-warning]', overlay);
    warning.innerHTML = images > 20 ? `<div class="sv-img-preflight-warning">Lote grande: ${images} geracoes. Confirme se todas as variantes sao realmente necessarias.</div>` : '<div class="sv-img-preflight-warning">Cada resultado permanecera em staging e exigira revisao humana antes da publicacao.</div>';
    overlay.classList.add('show');
    $('.sv-img-confirm', overlay)?.focus();
  }

  function typeFromLabel(label) {
    const normalized = String(label || '').toLocaleLowerCase('pt-BR').replace(/[()\/·-]+/g, ' ').replace(/\s+/g, ' ').trim();
    return TYPE_BY_LABEL[normalized] || Object.entries(TYPE_BY_LABEL).find(([key]) => normalized.includes(key))?.[1] || '';
  }

  function selectForRetry(productId, missingTypes) {
    const item = $(`.iv-item[data-product-id="${CSS.escape(String(productId))}"]`);
    if (!item) {
      window.alert('O produto nao esta mais na lista atual. Atualize os candidatos e tente novamente.');
      return;
    }
    const product = $('.iv-item>summary input.iv-check', item);
    if (product && !product.disabled && !product.checked) {
      product.checked = true;
      product.dispatchEvent(new Event('change', { bubbles: true }));
    }
    const wanted = new Set(missingTypes);
    $$('.iv-variant input.iv-check', item).forEach((input) => {
      if (input.disabled) return;
      const next = wanted.has(input.value);
      if (input.checked !== next) {
        input.checked = next;
        input.dispatchEvent(new Event('change', { bubbles: true }));
      }
    });
    item.open = true;
    item.scrollIntoView({ behavior: 'smooth', block: 'center' });
    saveDraft();
    setTimeout(() => $('#iv-run')?.click(), 250);
  }

  function addRowActions(job) {
    const productId = Number(job.productId || job.product_id || 0);
    if (!productId) return;
    const row = $(`#iv-progress-list .iv-progress-row[data-id="${CSS.escape(String(productId))}"]`);
    if (!row) return;
    const missing = Array.isArray(job.missing) ? job.missing.filter((type) => Object.prototype.hasOwnProperty.call(TYPES, type)) : [];
    let actions = $('.sv-img-progress-actions', row);
    if (!actions) {
      actions = document.createElement('span');
      actions.className = 'sv-img-progress-actions';
      row.appendChild(actions);
    }
    actions.innerHTML = '';
    if (missing.length) {
      const retry = document.createElement('button');
      retry.type = 'button';
      retry.className = 'sv-retry-missing';
      retry.textContent = `Tentar faltantes (${missing.length})`;
      retry.addEventListener('click', () => selectForRetry(productId, missing));
      actions.appendChild(retry);
    }
    if (job.lastError) {
      const copy = document.createElement('button');
      copy.type = 'button';
      copy.className = 'sv-copy-error';
      copy.textContent = 'Copiar erro';
      copy.addEventListener('click', async () => {
        try { await navigator.clipboard.writeText(String(job.lastError)); copy.textContent = 'Copiado'; }
        catch (_) { window.prompt('Copie o erro:', String(job.lastError)); }
      });
      actions.appendChild(copy);
    }
    const detail = $('small', row);
    if (detail && job.route) {
      let route = $('.sv-img-route', row);
      if (!route) { route = document.createElement('small'); route.className = 'sv-img-route'; row.appendChild(route); }
      route.textContent = job.route;
    }
  }

  function observeEnqueue(data) {
    const id = Number(data.job_id || 0);
    if (!id) return;
    const route = Array.isArray(data.provider_candidates) && data.provider_candidates.length
      ? `Rota visual: ${data.provider_requested || provider()} → ${data.provider_candidates.join(' → ')}`
      : `Estrategia solicitada: ${data.provider_requested || provider()}`;
    jobs.set(id, {
      ...(jobs.get(id) || {}),
      jobId: id,
      productId: Number(data.product_id || 0),
      channel: data.target_channel || channel(),
      provider: data.provider_requested || provider(),
      imageTypes: Array.isArray(data.image_types) ? data.image_types : [],
      route,
      savedAt: Date.now(),
      terminal: false
    });
    persistJobs();
  }

  function observeStatus(data) {
    if (!Array.isArray(data.jobs)) return;
    data.jobs.forEach((entry) => {
      const id = Number(entry.job_id || 0);
      const cached = jobs.get(id) || {};
      const missing = Array.isArray(entry.variants?.missing) ? entry.variants.missing : [];
      const job = {
        ...cached,
        jobId: id,
        productId: Number(entry.product_id || cached.productId || 0),
        missing,
        lastError: entry.last_error || '',
        resultState: entry.result_state || '',
        terminal: Boolean(entry.terminal),
        savedAt: Date.now()
      };
      jobs.set(id, job);
      addRowActions(job);
    });
    persistJobs();
  }

  function wrapFetch() {
    if (window.__svImageFetchObserved) return;
    window.__svImageFetchObserved = true;
    const nativeFetch = window.fetch.bind(window);
    window.fetch = async (...args) => {
      const response = await nativeFetch(...args);
      const raw = typeof args[0] === 'string' ? args[0] : args[0]?.url || '';
      if (/\/admin\/ai-image-studio\/api\/(enqueue_generation|generation_status)\.php/i.test(raw)) {
        response.clone().json().then((data) => {
          if (/enqueue_generation\.php/i.test(raw)) observeEnqueue(data || {});
          else observeStatus(data || {});
        }).catch(() => {});
      }
      return response;
    };
  }

  function insertResume() {
    if ($('#sv-img-resume')) return;
    const list = $('#iv-list');
    if (!list) return;
    const box = document.createElement('div');
    box.id = 'sv-img-resume';
    box.className = 'sv-img-resume';
    box.innerHTML = '<div><strong>Rascunho de selecao preservado</strong><small data-sv-resume-label></small></div><div><button type="button" data-sv-restore>Restaurar selecao</button> <button type="button" class="sv-img-clear-draft" data-sv-clear>Descartar rascunho</button></div>';
    list.insertAdjacentElement('beforebegin', box);
    $('[data-sv-restore]', box).addEventListener('click', applyDraft);
    $('[data-sv-clear]', box).addEventListener('click', clearDraft);
    updateResume();
  }

  function enhanceIntel() {
    const intel = $('#iv-intel');
    if (!intel || $('.sv-img-intel-toggle', intel)) return;
    const button = document.createElement('button');
    button.type = 'button';
    button.className = 'sv-img-intel-toggle';
    button.textContent = 'Ver regras do canal';
    button.addEventListener('click', () => {
      intel.classList.toggle('expanded');
      button.textContent = intel.classList.contains('expanded') ? 'Ocultar regras do canal' : 'Ver regras do canal';
    });
    intel.appendChild(button);
  }

  function initialize() {
    if (initialized || !$('#iv-generation') || !$('#iv-run')) return;
    initialized = true;
    insertResume();
    enhanceIntel();
    $('#iv-list')?.addEventListener('change', () => setTimeout(saveDraft, 0));
    $('#iv-channel')?.addEventListener('change', () => { setTimeout(() => { initialized = false; initialize(); updateResume(); }, 500); });
    $('#iv-provider')?.addEventListener('change', saveDraft);
    $('#iv-model')?.addEventListener('input', saveDraft);
    document.addEventListener('click', (event) => {
      const run = event.target.closest?.('#iv-run');
      if (!run) return;
      if (preflightApproved) { preflightApproved = false; saveDraft(); return; }
      if (run.disabled || !preflightRows().length) return;
      event.preventDefault();
      event.stopImmediatePropagation();
      showPreflight();
    }, true);
    const listObserver = new MutationObserver(() => {
      insertResume();
      enhanceIntel();
      requestAnimationFrame(applyDraft);
    });
    if ($('#iv-list')) listObserver.observe($('#iv-list'), { childList: true, subtree: false });
    setTimeout(applyDraft, 250);
  }

  loadJobs();
  wrapFetch();
  initialize();
  const observer = new MutationObserver(initialize);
  observer.observe(document.body, { childList: true, subtree: true });
})();

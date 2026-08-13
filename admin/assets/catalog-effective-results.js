(() => {
  'use strict';

  const PAGE = '/admin/catalog-optimization/admin_catalog.php';
  if (window.location.pathname.replace(/\/+$/, '') !== PAGE) return;

  const $ = (selector, root = document) => root.querySelector(selector);
  const $$ = (selector, root = document) => [...root.querySelectorAll(selector)];
  const state = {
    filter: 'all',
    query: '',
    initialized: false,
  };

  const style = document.createElement('style');
  style.id = 'sv-effective-results-style';
  style.textContent = `
    :root{--sv-eff:#7e22ce;--sv-eff-dark:#581c87;--sv-eff-soft:#faf5ff;--sv-eff-line:#d8b4fe;--sv-eff-muted:#64748b;--sv-eff-red:#991b1b;--sv-eff-green:#166534}
    .sv-effective-toolbar{display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin:0 0 12px;padding:11px 12px;border:1px solid #e2e8f0;border-radius:11px;background:#fff;position:sticky;top:8px;z-index:24;box-shadow:0 8px 24px rgba(15,23,42,.05)}
    .sv-effective-search{display:flex;align-items:center;gap:7px;flex:1 1 260px;min-width:190px;border:1px solid #cbd5e1;border-radius:9px;background:#fff;padding:0 10px}.sv-effective-search input{width:100%;min-height:42px;border:0;outline:0;font:inherit;background:transparent}
    .sv-effective-filter,.sv-effective-action{border:0;border-radius:9px;padding:9px 11px;min-height:42px;font:inherit;font-weight:850;cursor:pointer;background:#eef2f7;color:#334155}.sv-effective-filter.is-active{background:#ede9fe;color:var(--sv-eff-dark)}.sv-effective-action.primary{background:var(--sv-eff);color:#fff}
    .sv-effective-count{margin-left:auto;font-size:12px;font-weight:850;color:#475569;white-space:nowrap}
    .sv-result-check{display:inline-flex;align-items:center;gap:6px;font-size:12px;font-weight:850;color:#334155;cursor:pointer;min-height:44px}.sv-result-check input{width:20px!important;height:20px!important;margin:0!important;accent-color:var(--sv-eff)!important}.sv-result-check span{display:none}
    .sv-effective-panel{margin:0 0 12px;border:1px solid var(--sv-eff-line);border-radius:11px;background:var(--sv-eff-soft);overflow:hidden}.sv-effective-head{display:flex;align-items:flex-start;justify-content:space-between;gap:10px;padding:12px 13px}.sv-effective-head h3{margin:0 0 3px;font-size:16px;color:var(--sv-eff-dark)}.sv-effective-head p{margin:0;color:#6b21a8;font-size:12px;line-height:1.4}.sv-effective-badge{display:inline-flex;align-items:center;border-radius:999px;background:#ede9fe;color:var(--sv-eff-dark);padding:5px 8px;font-size:11px;font-weight:900;white-space:nowrap}
    .sv-effective-list{display:grid;gap:7px;padding:0 12px 12px}.sv-effective-change{border:1px solid #e9d5ff;border-radius:9px;background:#fff;overflow:hidden}.sv-effective-change>summary{list-style:none;display:flex;align-items:center;justify-content:space-between;gap:9px;padding:10px 11px;cursor:pointer;font-weight:850;color:#3b0764}.sv-effective-change>summary::-webkit-details-marker{display:none}.sv-effective-change>summary::after{content:'▾';color:#7e22ce}.sv-effective-change[open]>summary::after{transform:rotate(180deg)}.sv-effective-target{font-size:11px;font-weight:750;color:#7c3aed;margin-left:auto;text-align:right}
    .sv-effective-comparison{display:grid;grid-template-columns:minmax(0,1fr) minmax(0,1fr);gap:8px;padding:0 10px 10px}.sv-effective-side{min-width:0;border-radius:8px;padding:9px;background:#f8fafc;border:1px solid #e2e8f0}.sv-effective-side.is-new{background:#f0fdf4;border-color:#bbf7d0}.sv-effective-side strong{display:block;font-size:11px;text-transform:uppercase;letter-spacing:.04em;color:#64748b;margin-bottom:5px}.sv-effective-value{white-space:pre-wrap;overflow-wrap:anywhere;word-break:break-word;font-size:13px;line-height:1.45;color:#172033}.sv-effective-value ul{margin:0;padding-left:18px}
    .sv-effective-empty{margin:0 12px 12px;padding:11px;border:1px solid #f4dda0;border-radius:9px;background:#fff8e1;color:#6b5300;font-size:13px;line-height:1.45}.sv-effective-error{margin:0 12px 12px;padding:11px;border:1px solid #fecaca;border-radius:9px;background:#fef2f2;color:var(--sv-eff-red);font-size:13px}.sv-effective-loading{margin:0 12px 12px;color:#6b21a8;font-size:13px}
    .sv-effective-panel-actions{display:flex;gap:7px;align-items:center;flex-wrap:wrap;padding:0 12px 12px}.sv-effective-panel-actions button{border:0;border-radius:8px;padding:8px 10px;min-height:40px;font:inherit;font-size:12px;font-weight:850;cursor:pointer;background:#ede9fe;color:var(--sv-eff-dark)}
    .sv-effective-internal{border:1px solid #e2e8f0;border-radius:9px;background:#fff;margin:0 12px 12px}.sv-effective-internal>summary{cursor:pointer;padding:9px 10px;font-size:12px;font-weight:850;color:#475569}.sv-effective-internal-list{padding:0 10px 10px;display:grid;gap:5px}.sv-effective-internal-list div{font-size:12px;color:#64748b}
    .sv-effective-ready:not(.sv-effective-expanded) .compare,.sv-effective-ready:not(.sv-effective-expanded) .sv-original-details,.sv-effective-ready:not(.sv-effective-expanded) .sv-regen-details,.sv-effective-ready:not(.sv-effective-expanded) .sv-tech-details,.sv-effective-ready:not(.sv-effective-expanded) .quality,.sv-effective-ready:not(.sv-effective-expanded) .sv-quality-friendly,.sv-effective-ready:not(.sv-effective-expanded)>.profile,.sv-effective-ready:not(.sv-effective-expanded)>.endpoints,.sv-effective-ready:not(.sv-effective-expanded) .regen{display:none!important}
    .sv-effective-noop .publish{opacity:.5!important;cursor:not-allowed!important}.sv-effective-hidden{display:none!important}
    details.sv-candidate-collapsible{display:block!important;padding:0!important;overflow:hidden}.sv-candidate-collapsible>summary{list-style:none;display:grid;grid-template-columns:auto minmax(0,1fr) auto;gap:11px;align-items:center;padding:11px 12px;cursor:pointer}.sv-candidate-collapsible>summary::-webkit-details-marker{display:none}.sv-candidate-collapsible>summary::after{content:'▾';color:#64748b}.sv-candidate-collapsible[open]>summary::after{transform:rotate(180deg)}.sv-candidate-checkbox{display:inline-flex;align-items:center;gap:6px;cursor:pointer;min-height:44px}.sv-candidate-checkbox input{width:20px!important;height:20px!important;margin:0!important}.sv-candidate-collapsible .sv-candidate-main{min-width:0}.sv-candidate-detail{padding:9px 12px 12px;border-top:1px solid #edf2f7;background:#fbfcfe}.sv-candidate-detail .sv-readiness{justify-content:flex-start}
    .sv-effective-mobile-bar{display:none}
    @media(max-width:760px){
      .sv-effective-toolbar{top:4px;padding:8px;gap:6px}.sv-effective-search{flex-basis:100%;min-width:0}.sv-effective-filter{flex:1 1 auto;padding:8px 9px;font-size:12px}.sv-effective-count{width:100%;margin-left:0;text-align:center}
      .sv-result-check span{display:inline}.sv-review-summary{grid-template-columns:minmax(0,1fr)!important;gap:8px!important}.sv-review-summary>.sv-result-check{grid-row:1;justify-self:start}.sv-review-summary>.sv-review-title{grid-row:2}.sv-review-summary>.sv-review-actions{grid-row:3;justify-content:flex-start!important}.sv-result-state{white-space:normal!important}
      .sv-effective-head{padding:10px;flex-direction:column}.sv-effective-list{padding:0 9px 9px}.sv-effective-comparison{grid-template-columns:1fr;padding:0 8px 8px}.sv-effective-target{display:none}.sv-effective-panel-actions{display:grid;grid-template-columns:1fr 1fr;padding:0 9px 9px}.sv-effective-panel-actions button{min-height:44px}
      .sv-candidate-collapsible>summary{grid-template-columns:auto minmax(0,1fr);padding:9px;gap:8px}.sv-candidate-collapsible>summary::after{grid-column:2;justify-self:end}.sv-candidate-detail{padding:8px 9px 10px}
      .sv-effective-mobile-bar{position:fixed;left:7px;right:7px;bottom:calc(7px + env(safe-area-inset-bottom));z-index:95;display:grid;grid-template-columns:1fr 1fr 1fr;gap:6px;padding:8px;border:1px solid #d8b4fe;border-radius:12px;background:rgba(255,255,255,.98);box-shadow:0 14px 34px rgba(15,23,42,.2)}.sv-effective-mobile-bar strong{grid-column:1/-1;text-align:center;font-size:12px;color:var(--sv-eff-dark)}.sv-effective-mobile-bar button{border:0;border-radius:8px;min-height:44px;padding:7px;font:inherit;font-size:11px;font-weight:850;background:#ede9fe;color:var(--sv-eff-dark)}
      body{padding-bottom:108px!important}body.sv-core-bulk-visible .sv-effective-mobile-bar{display:none}
    }
  `;
  document.head.appendChild(style);

  async function fetchJson(url) {
    const response = await fetch(url, { credentials: 'same-origin', headers: { Accept: 'application/json' } });
    if (response.redirected || /\/auth\/login\.php/i.test(response.url || '')) {
      throw new Error('A sessão administrativa expirou. Entre novamente.');
    }
    const text = await response.text();
    let data;
    try { data = JSON.parse(text); } catch (_) { throw new Error(`Resposta inválida do servidor (HTTP ${response.status}).`); }
    if (!response.ok || data?.success === false) throw new Error(data?.error || `Falha HTTP ${response.status}.`);
    return data;
  }

  function formatValue(value) {
    if (Array.isArray(value)) return value.map((item) => String(item || '').trim()).filter(Boolean);
    if (value === null || value === undefined) return '';
    return String(value).trim();
  }

  function renderValue(value) {
    const host = document.createElement('div');
    host.className = 'sv-effective-value';
    const normalized = formatValue(value);
    if (Array.isArray(normalized)) {
      if (!normalized.length) {
        host.textContent = '— vazio';
        return host;
      }
      const list = document.createElement('ul');
      normalized.forEach((entry) => {
        const item = document.createElement('li');
        item.textContent = entry;
        list.appendChild(item);
      });
      host.appendChild(list);
      return host;
    }
    host.textContent = normalized || '— vazio';
    return host;
  }

  function createChange(change, index) {
    const details = document.createElement('details');
    details.className = 'sv-effective-change';
    if (index === 0) details.open = true;
    const summary = document.createElement('summary');
    const label = document.createElement('span');
    label.textContent = change.label || change.field || 'Campo alterado';
    const target = document.createElement('span');
    target.className = 'sv-effective-target';
    target.textContent = change.mode === 'embedded' ? `incorporado em ${change.target || 'outro campo'}` : (change.target || 'campo direto do canal');
    summary.append(label, target);

    const compare = document.createElement('div');
    compare.className = 'sv-effective-comparison';
    const before = document.createElement('div');
    before.className = 'sv-effective-side';
    const beforeTitle = document.createElement('strong');
    beforeTitle.textContent = 'Atual';
    before.append(beforeTitle, renderValue(change.before));
    const after = document.createElement('div');
    after.className = 'sv-effective-side is-new';
    const afterTitle = document.createElement('strong');
    afterTitle.textContent = 'Novo';
    after.append(afterTitle, renderValue(change.after));
    compare.append(before, after);
    details.append(summary, compare);
    return details;
  }

  function stagingId(article) {
    return Number($('input[name="staging_id"]', article)?.value || $('input[name="selected_ids[]"]', article)?.value || 0);
  }

  function resultBody(article) {
    return $('.sv-review-body', article) || article;
  }

  function wrapResultCheckbox(article) {
    const checkbox = $('input[name="selected_ids[]"]', article);
    if (!checkbox || checkbox.closest('.sv-result-check')) return checkbox;
    const wrapper = document.createElement('label');
    wrapper.className = 'sv-result-check';
    const text = document.createElement('span');
    text.textContent = 'Selecionar';
    checkbox.replaceWith(wrapper);
    wrapper.append(checkbox, text);
    wrapper.addEventListener('click', (event) => event.stopPropagation());
    checkbox.addEventListener('change', () => {
      article.classList.toggle('sv-ai-selected', checkbox.checked);
      updateSelectionUi();
      applyFilters();
    });
    return checkbox;
  }

  function toggleExpanded(article, button) {
    const expanded = article.classList.toggle('sv-effective-expanded');
    button.textContent = expanded ? 'Ocultar edição completa' : 'Editar conteúdo completo';
    button.setAttribute('aria-expanded', expanded ? 'true' : 'false');
    if (expanded) article.classList.add('is-open');
  }

  function renderPanel(article, payload) {
    const body = resultBody(article);
    let panel = $('.sv-effective-panel', article);
    if (!panel) {
      panel = document.createElement('section');
      panel.className = 'sv-effective-panel';
      body.prepend(panel);
    }
    panel.innerHTML = '';
    const changes = payload?.changes || {};
    const effective = Array.isArray(changes.effective) ? changes.effective : [];
    const internal = Array.isArray(changes.internal) ? changes.internal : [];

    const head = document.createElement('div');
    head.className = 'sv-effective-head';
    const copy = document.createElement('div');
    const title = document.createElement('h3');
    title.textContent = effective.length ? 'O que realmente será alterado' : 'Nenhuma alteração efetiva neste canal';
    const description = document.createElement('p');
    description.textContent = effective.length
      ? 'O resumo abaixo omite campos inalterados. Abra um campo para comparar o valor atual com o novo.'
      : 'O conteúdo pode ter notas internas, mas não produziria mudança no destino atual.';
    copy.append(title, description);
    const badge = document.createElement('span');
    badge.className = 'sv-effective-badge';
    badge.textContent = `${effective.length} mudança${effective.length === 1 ? '' : 's'}`;
    head.append(copy, badge);
    panel.appendChild(head);

    if (effective.length) {
      const list = document.createElement('div');
      list.className = 'sv-effective-list';
      effective.forEach((change, index) => list.appendChild(createChange(change, index)));
      panel.appendChild(list);
    } else {
      const empty = document.createElement('div');
      empty.className = 'sv-effective-empty';
      empty.textContent = 'Aplicar foi bloqueado para evitar uma publicação sem efeito. Edite ou regenere o rascunho, ou rejeite-o.';
      panel.appendChild(empty);
    }

    if (internal.length) {
      const details = document.createElement('details');
      details.className = 'sv-effective-internal';
      const summary = document.createElement('summary');
      summary.textContent = `${internal.length} ajuste${internal.length === 1 ? '' : 's'} apenas interno${internal.length === 1 ? '' : 's'}`;
      const list = document.createElement('div');
      list.className = 'sv-effective-internal-list';
      internal.forEach((change) => {
        const row = document.createElement('div');
        row.textContent = `${change.label || change.field}: não é enviado como campo independente neste canal.`;
        list.appendChild(row);
      });
      details.append(summary, list);
      panel.appendChild(details);
    }

    const actions = document.createElement('div');
    actions.className = 'sv-effective-panel-actions';
    const openAll = document.createElement('button');
    openAll.type = 'button';
    openAll.textContent = 'Abrir mudanças';
    openAll.addEventListener('click', () => {
      $$('.sv-effective-change', panel).forEach((item) => { item.open = true; });
    });
    const edit = document.createElement('button');
    edit.type = 'button';
    edit.textContent = 'Editar conteúdo completo';
    edit.setAttribute('aria-expanded', 'false');
    edit.addEventListener('click', () => toggleExpanded(article, edit));
    actions.append(openAll, edit);
    panel.appendChild(actions);

    article.classList.add('sv-effective-ready');
    article.classList.toggle('sv-effective-noop', effective.length === 0);
    article.dataset.effectiveCount = String(effective.length);
    article.dataset.effectiveLoaded = '1';
    const publish = $('.publish', article);
    if (publish) {
      if (effective.length === 0) {
        publish.disabled = true;
        publish.dataset.effectiveNoop = '1';
        publish.title = 'Sem alterações efetivas para aplicar neste canal.';
      } else if (publish.dataset.effectiveNoop === '1') {
        publish.disabled = false;
        delete publish.dataset.effectiveNoop;
      }
    }
  }

  async function enhanceResult(article) {
    if (!(article instanceof HTMLElement) || article.dataset.effectiveLoading === '1' || article.dataset.effectiveLoaded === '1') return;
    const id = stagingId(article);
    if (!id) return;
    article.dataset.effectiveLoading = '1';
    const body = resultBody(article);
    const loading = document.createElement('div');
    loading.className = 'sv-effective-loading';
    loading.textContent = 'Calculando somente as alterações efetivas...';
    body.prepend(loading);
    wrapResultCheckbox(article);
    const toggle = $('.sv-toggle', article);
    if (toggle) {
      toggle.setAttribute('aria-expanded', article.classList.contains('is-open') ? 'true' : 'false');
      toggle.addEventListener('click', () => {
        window.setTimeout(() => toggle.setAttribute('aria-expanded', article.classList.contains('is-open') ? 'true' : 'false'), 0);
      });
    }
    try {
      const payload = await fetchJson(`/admin/catalog-optimization/api/effective_changes.php?staging_id=${encodeURIComponent(id)}`);
      loading.remove();
      renderPanel(article, payload);
    } catch (error) {
      loading.className = 'sv-effective-error';
      loading.textContent = error.message;
      article.dataset.effectiveLoaded = 'error';
    } finally {
      delete article.dataset.effectiveLoading;
      applyFilters();
      updateSelectionUi();
    }
  }

  function enhanceCandidate(label) {
    if (!(label instanceof HTMLElement) || label.dataset.collapsibleReady === '1' || label.matches('details')) return;
    const checkbox = $('input[data-candidate-id]', label);
    const main = $('.sv-candidate-main', label);
    const readiness = $('.sv-readiness', label);
    if (!checkbox || !main) return;

    const details = document.createElement('details');
    details.className = `${label.className} sv-candidate-collapsible`;
    details.dataset.collapsibleReady = '1';
    const summary = document.createElement('summary');
    const checkLabel = document.createElement('label');
    checkLabel.className = 'sv-candidate-checkbox';
    const selectText = document.createElement('span');
    selectText.textContent = 'Selecionar';
    checkbox.remove();
    checkLabel.append(checkbox, selectText);
    checkLabel.addEventListener('click', (event) => event.stopPropagation());
    main.remove();
    summary.append(checkLabel, main);
    const body = document.createElement('div');
    body.className = 'sv-candidate-detail';
    if (readiness) {
      readiness.remove();
      body.appendChild(readiness);
    }
    details.append(summary, body);
    label.replaceWith(details);
    const sync = () => details.classList.toggle('is-selected', checkbox.checked);
    checkbox.addEventListener('change', sync);
    sync();
  }

  function enhanceCandidates() {
    $$('.sv-candidates > .sv-candidate:not(details)').forEach(enhanceCandidate);
  }

  function resultArticles() {
    return $$('article.item, article.sv-review-card').filter((article, index, all) => all.indexOf(article) === index && stagingId(article) > 0);
  }

  function resultState(article) {
    if (article.classList.contains('has-failure') || article.dataset.svState === 'fail') return 'failure';
    if (article.dataset.effectiveLoaded !== '1') return 'pending';
    if (Number(article.dataset.effectiveCount || 0) > 0 && (article.classList.contains('is-ready') || article.dataset.svState === 'ready')) return 'ready';
    return 'pending';
  }

  function applyFilters() {
    const articles = resultArticles();
    const query = state.query.toLocaleLowerCase('pt-BR').trim();
    let visible = 0;
    articles.forEach((article) => {
      const checkbox = $('input[name="selected_ids[]"]', article);
      const selected = Boolean(checkbox?.checked);
      const status = resultState(article);
      const matchesFilter = state.filter === 'all'
        || (state.filter === 'selected' && selected)
        || (state.filter === status);
      const haystack = `${article.dataset.svSearch || ''} ${article.textContent || ''}`.toLocaleLowerCase('pt-BR');
      const matchesQuery = !query || haystack.includes(query);
      const show = matchesFilter && matchesQuery;
      article.classList.toggle('sv-effective-hidden', !show);
      if (show) visible += 1;
    });
    const count = $('#sv-effective-visible-count');
    if (count) count.textContent = `${visible} de ${articles.length} resultado${articles.length === 1 ? '' : 's'}`;
    updateSelectionUi();
  }

  function updateSelectionUi() {
    const articles = resultArticles();
    const selected = articles.filter((article) => $('input[name="selected_ids[]"]', article)?.checked).length;
    const label = $('#sv-effective-selection-count');
    if (label) label.textContent = `${selected} selecionado${selected === 1 ? '' : 's'}`;
    const coreBulk = $('.sv-bulk-bar');
    document.body.classList.toggle('sv-core-bulk-visible', Boolean(coreBulk?.classList.contains('show')));
  }

  function selectVisible() {
    resultArticles().filter((article) => !article.classList.contains('sv-effective-hidden')).forEach((article) => {
      const checkbox = $('input[name="selected_ids[]"]', article);
      if (checkbox && !checkbox.disabled && !checkbox.checked) {
        checkbox.checked = true;
        checkbox.dispatchEvent(new Event('change', { bubbles: true }));
      }
    });
  }

  function clearSelection() {
    resultArticles().forEach((article) => {
      const checkbox = $('input[name="selected_ids[]"]', article);
      if (checkbox?.checked) {
        checkbox.checked = false;
        checkbox.dispatchEvent(new Event('change', { bubbles: true }));
      }
    });
  }

  function openSelected() {
    const selected = resultArticles().filter((article) => $('input[name="selected_ids[]"]', article)?.checked);
    selected.forEach((article) => {
      article.classList.add('is-open');
      const toggle = $('.sv-toggle', article);
      if (toggle) {
        toggle.textContent = 'Fechar';
        toggle.setAttribute('aria-expanded', 'true');
      }
    });
    selected[0]?.scrollIntoView({ behavior: 'smooth', block: 'start' });
  }

  function ensureToolbar() {
    if ($('#sv-effective-toolbar')) return;
    const heading = $('#sv-review') || $('.sv-review-heading') || resultArticles()[0];
    if (!heading) return;
    const coreFilters = $('.sv-review-filters');
    if (coreFilters) coreFilters.hidden = true;

    const toolbar = document.createElement('div');
    toolbar.id = 'sv-effective-toolbar';
    toolbar.className = 'sv-effective-toolbar';
    toolbar.innerHTML = '<label class="sv-effective-search"><span aria-hidden="true">⌕</span><input type="search" placeholder="Buscar produto, SKU, canal ou status" aria-label="Buscar resultados"></label><button type="button" class="sv-effective-filter is-active" data-effective-filter="all">Todos</button><button type="button" class="sv-effective-filter" data-effective-filter="pending">Pendentes</button><button type="button" class="sv-effective-filter" data-effective-filter="ready">Prontos</button><button type="button" class="sv-effective-filter" data-effective-filter="failure">Falhas</button><button type="button" class="sv-effective-filter" data-effective-filter="selected">Selecionados</button><span id="sv-effective-visible-count" class="sv-effective-count"></span>';
    heading.insertAdjacentElement('afterend', toolbar);
    $('input', toolbar).addEventListener('input', (event) => {
      state.query = event.target.value || '';
      applyFilters();
    });
    $$('[data-effective-filter]', toolbar).forEach((button) => button.addEventListener('click', () => {
      state.filter = button.dataset.effectiveFilter || 'all';
      $$('[data-effective-filter]', toolbar).forEach((item) => item.classList.toggle('is-active', item === button));
      applyFilters();
    }));
  }

  function ensureMobileBar() {
    if ($('#sv-effective-mobile-bar')) return;
    const bar = document.createElement('div');
    bar.id = 'sv-effective-mobile-bar';
    bar.className = 'sv-effective-mobile-bar';
    bar.innerHTML = '<strong id="sv-effective-selection-count">0 selecionados</strong><button type="button" data-mobile-select>Selecionar visíveis</button><button type="button" data-mobile-clear>Limpar</button><button type="button" data-mobile-open>Abrir selecionados</button>';
    document.body.appendChild(bar);
    $('[data-mobile-select]', bar).addEventListener('click', selectVisible);
    $('[data-mobile-clear]', bar).addEventListener('click', clearSelection);
    $('[data-mobile-open]', bar).addEventListener('click', openSelected);
  }

  function protectBulkPublish() {
    const form = $('#bulk-action-form');
    if (!form || form.dataset.effectiveGuard === '1') return;
    form.dataset.effectiveGuard = '1';
    form.addEventListener('submit', (event) => {
      const action = $('[name="bulk_action"]', form)?.value || '';
      if (action !== 'bulk_publish') return;
      const selected = resultArticles().filter((article) => $('input[name="selected_ids[]"]', article)?.checked);
      const loading = selected.filter((article) => article.dataset.effectiveLoaded !== '1');
      if (loading.length) {
        event.preventDefault();
        window.alert('Aguarde o cálculo das alterações efetivas dos itens selecionados.');
        return;
      }
      const noop = selected.filter((article) => Number(article.dataset.effectiveCount || 0) === 0);
      if (noop.length) {
        event.preventDefault();
        noop.forEach((article) => article.classList.add('is-open'));
        window.alert(`${noop.length} item(ns) não possuem alteração efetiva. Edite, regenere ou rejeite esses rascunhos antes do lote.`);
      }
    }, true);
  }

  function run() {
    ensureToolbar();
    ensureMobileBar();
    protectBulkPublish();
    enhanceCandidates();
    resultArticles().forEach((article) => {
      wrapResultCheckbox(article);
      enhanceResult(article);
    });
    applyFilters();
    updateSelectionUi();
  }

  const observer = new MutationObserver(() => window.requestAnimationFrame(run));
  observer.observe(document.documentElement, { childList: true, subtree: true });
  document.addEventListener('change', (event) => {
    if (event.target.matches?.('input[name="selected_ids[]"]')) {
      applyFilters();
      updateSelectionUi();
    }
  });
  window.setInterval(updateSelectionUi, 700);
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', run, { once: true }); else run();
})();

(() => {
  'use strict';

  const path = window.location.pathname.replace(/\/+$/, '') || '/';
  const isHome = path === '/admin' || path === '/admin/index.php';
  const isCatalog = path === '/admin/catalog-optimization/admin_catalog.php';
  if (!isHome && !isCatalog) return;

  const $ = (selector, root = document) => root.querySelector(selector);
  const $$ = (selector, root = document) => [...root.querySelectorAll(selector)];

  const style = document.createElement('style');
  style.id = 'sv-admin-mobile-completion-style';
  style.textContent = `
    .sv-admin-section-actions{display:none;align-items:center;gap:7px;margin:0 0 9px}.sv-admin-section-actions button{border:0;border-radius:8px;min-height:40px;padding:8px 10px;background:#eef2f7;color:#334155;font:inherit;font-size:12px;font-weight:850;cursor:pointer}
    .sv-admin-card-host{padding:0!important;overflow:hidden!important}.sv-admin-card-details{display:block}.sv-admin-card-details>summary{list-style:none;cursor:pointer;padding:13px}.sv-admin-card-details>summary::-webkit-details-marker{display:none}.sv-admin-card-details>summary .admin-card-head{margin:0!important}.sv-admin-card-details>summary .admin-card-head::after{content:'▾';margin-left:auto;color:#64748b;transition:transform .15s ease}.sv-admin-card-details[open]>summary .admin-card-head::after{transform:rotate(180deg)}.sv-admin-card-details-body{padding:0 13px 13px}
    .sv-admin-mobile-dock{display:none}.sv-admin-sort-wrap{display:inline-flex;align-items:center;gap:6px;min-height:42px;padding:0 9px;border:1px solid #cbd5e1;border-radius:9px;background:#fff;color:#475569;font-size:12px;font-weight:850}.sv-admin-sort-wrap select{min-height:40px;border:0;outline:0;background:transparent;color:#172033;font:inherit;font-weight:750;cursor:pointer}.sv-admin-sort-note{font-size:11px;color:#64748b;white-space:nowrap}
    @media(max-width:720px){
      .sv-admin-section-actions{display:flex}.sv-admin-card-details>summary{padding:11px 12px}.sv-admin-card-details-body{padding:0 12px 12px}.sv-admin-card-details:not([open])>summary{background:#fff}.sv-admin-card-details>summary .admin-card-head h2{font-size:16px!important}.sv-admin-card-details>summary .eyebrow{font-size:10px!important}
      body.admin-surface{padding-bottom:calc(77px + env(safe-area-inset-bottom))!important}.sv-admin-mobile-dock{position:fixed;left:7px;right:7px;bottom:calc(7px + env(safe-area-inset-bottom));z-index:10020;display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:5px;padding:7px;border:1px solid rgba(148,163,184,.5);border-radius:14px;background:rgba(15,23,42,.96);box-shadow:0 14px 36px rgba(15,23,42,.3);backdrop-filter:blur(10px)}.sv-admin-mobile-dock a{display:grid;place-items:center;gap:2px;min-height:48px;padding:5px 2px;border-radius:9px;color:#fff;text-decoration:none;text-align:center;font-size:10px;font-weight:850}.sv-admin-mobile-dock a:active{background:rgba(255,255,255,.13)}.sv-admin-mobile-dock span{font-size:18px;line-height:1}
      .sv-effective-toolbar .sv-admin-sort-wrap{order:20;flex:1 1 100%;justify-content:space-between}.sv-admin-sort-wrap select{flex:1;min-width:0}.sv-admin-sort-note{display:none}
    }
  `;
  document.head.appendChild(style);

  function enhanceHomeSections() {
    const overview = $('.admin-overview');
    if (!overview) return;
    const cards = $$('.admin-card', overview).filter((card) => !card.closest('.sv-admin-card-details-body .admin-card'));
    if (!cards.length) return;
    const mobile = window.matchMedia('(max-width:720px)');
    const storageKey = 'svAdminOpenSectionsV1';
    let saved = [];
    try {
      const parsed = JSON.parse(localStorage.getItem(storageKey) || '[]');
      if (Array.isArray(parsed)) saved = parsed;
    } catch (_) {}

    const detailsList = [];
    cards.forEach((card, index) => {
      if (card.dataset.svAccordion === '1') {
        const existing = $('.sv-admin-card-details', card);
        if (existing) detailsList.push(existing);
        return;
      }
      const head = card.querySelector(':scope > .admin-card-head');
      if (!head) return;
      const title = $('h2', head)?.textContent?.trim() || `Seção ${index + 1}`;
      const sectionKey = title.toLocaleLowerCase('pt-BR').replace(/[^a-z0-9]+/g, '-').replace(/^-|-$/g, '') || `section-${index}`;
      card.dataset.svAccordion = '1';
      card.classList.add('sv-admin-card-host');

      const details = document.createElement('details');
      details.className = 'sv-admin-card-details';
      details.dataset.sectionKey = sectionKey;
      const summary = document.createElement('summary');
      const body = document.createElement('div');
      body.className = 'sv-admin-card-details-body';
      summary.appendChild(head);
      [...card.childNodes].forEach((node) => {
        if (node !== details && node !== head) body.appendChild(node);
      });
      details.append(summary, body);
      card.appendChild(details);
      details.open = mobile.matches ? (saved.length ? saved.includes(sectionKey) : index === 0) : true;
      details.addEventListener('toggle', () => {
        if (!mobile.matches) return;
        const openKeys = detailsList.filter((item) => item.open).map((item) => item.dataset.sectionKey).filter(Boolean);
        try { localStorage.setItem(storageKey, JSON.stringify(openKeys)); } catch (_) {}
      });
      detailsList.push(details);
    });

    if (!$('#sv-admin-section-actions')) {
      const actions = document.createElement('div');
      actions.id = 'sv-admin-section-actions';
      actions.className = 'sv-admin-section-actions';
      actions.innerHTML = '<button type="button" data-sections-open>Abrir seções</button><button type="button" data-sections-close>Recolher seções</button>';
      const firstCard = cards[0];
      firstCard?.insertAdjacentElement('beforebegin', actions);
      $('[data-sections-open]', actions)?.addEventListener('click', () => detailsList.forEach((details) => { details.open = true; }));
      $('[data-sections-close]', actions)?.addEventListener('click', () => detailsList.forEach((details) => { details.open = false; }));
    }

    const syncViewport = () => {
      if (!mobile.matches) detailsList.forEach((details) => { details.open = true; });
    };
    if (typeof mobile.addEventListener === 'function') mobile.addEventListener('change', syncViewport);
  }

  function ensureHomeDock() {
    if ($('#sv-admin-mobile-dock')) return;
    const dock = document.createElement('nav');
    dock.id = 'sv-admin-mobile-dock';
    dock.className = 'sv-admin-mobile-dock';
    dock.setAttribute('aria-label', 'Ações principais do Admin');
    const links = [
      ['/admin/ai-image-studio/admin_dashboard.php', '🖼️', 'Imagens'],
      ['/admin/catalog-optimization/admin_catalog.php', '📝', 'Otimizar'],
      ['/admin/produtos.php', '📦', 'Produtos'],
      ['/admin/pedidos.php', '📋', 'Pedidos'],
    ];
    links.forEach(([href, icon, label]) => {
      const link = document.createElement('a');
      link.href = href;
      const iconNode = document.createElement('span');
      iconNode.setAttribute('aria-hidden', 'true');
      iconNode.textContent = icon;
      const labelNode = document.createElement('b');
      labelNode.textContent = label;
      link.append(iconNode, labelNode);
      dock.appendChild(link);
    });
    document.body.appendChild(dock);
  }

  function stagingId(article) {
    return Number($('input[name="staging_id"]', article)?.value || $('input[name="selected_ids[]"]', article)?.value || 0);
  }

  function resultCards() {
    return $$('article.sv-review-card, article.item').filter((article, index, all) => all.indexOf(article) === index && stagingId(article) > 0);
  }

  function normalized(value) {
    return String(value || '').trim().toLocaleLowerCase('pt-BR');
  }

  function resultTitle(article) {
    return normalized($('.sv-review-title strong,.source-title strong,.source-title', article)?.textContent || article.dataset.svSearch || '');
  }

  function resultChannel(article) {
    const text = normalized(`${article.dataset.svSearch || ''} ${article.textContent || ''}`);
    const known = ['mercado livre', 'shopee', 'amazon', 'site'];
    return known.find((channel) => text.includes(channel)) || text;
  }

  function resultStatus(article) {
    if (article.classList.contains('has-failure') || article.dataset.svState === 'fail') return 'falha';
    if (article.classList.contains('is-ready') || article.dataset.svState === 'ready') return 'pronto';
    if (article.dataset.effectiveLoaded !== '1') return 'pendente';
    if (Number(article.dataset.effectiveCount || 0) === 0) return 'sem alteração';
    return normalized($('.sv-result-state', article)?.textContent || 'revisão');
  }

  function urgentRank(article) {
    const status = resultStatus(article);
    if (status === 'falha') return 0;
    if (status === 'pendente') return 1;
    if (status === 'revisão') return 2;
    if (status === 'sem alteração') return 3;
    return 4;
  }

  function sortCatalog(mode) {
    const cards = resultCards();
    const groups = new Map();
    cards.forEach((card, index) => {
      if (!card.dataset.svOriginalOrder) card.dataset.svOriginalOrder = String(index);
      const parent = card.parentElement;
      if (!parent) return;
      if (!groups.has(parent)) groups.set(parent, []);
      groups.get(parent).push(card);
    });
    const collator = new Intl.Collator('pt-BR', { sensitivity: 'base', numeric: true });
    groups.forEach((items, parent) => {
      items.sort((a, b) => {
        if (mode === 'urgent') return urgentRank(a) - urgentRank(b) || stagingId(b) - stagingId(a);
        if (mode === 'channel') return collator.compare(resultChannel(a), resultChannel(b)) || collator.compare(resultTitle(a), resultTitle(b));
        if (mode === 'status') return collator.compare(resultStatus(a), resultStatus(b)) || stagingId(b) - stagingId(a);
        if (mode === 'product') return collator.compare(resultTitle(a), resultTitle(b));
        return stagingId(b) - stagingId(a);
      });
      items.forEach((item) => parent.appendChild(item));
    });
    const note = $('#sv-admin-sort-note');
    if (note) note.textContent = `${cards.length} resultado${cards.length === 1 ? '' : 's'} ordenado${cards.length === 1 ? '' : 's'}`;
  }

  function ensureCatalogSort() {
    const toolbar = $('#sv-effective-toolbar');
    if (!toolbar || $('#sv-effective-sort')) return Boolean(toolbar);
    const wrap = document.createElement('label');
    wrap.className = 'sv-admin-sort-wrap';
    wrap.setAttribute('for', 'sv-effective-sort');
    const label = document.createElement('span');
    label.textContent = 'Ordenar';
    const select = document.createElement('select');
    select.id = 'sv-effective-sort';
    select.dataset.effectiveSort = '1';
    select.setAttribute('aria-label', 'Ordenar resultados');
    select.innerHTML = '<option value="recent">Mais recentes</option><option value="urgent">Mais urgentes</option><option value="channel">Por canal</option><option value="status">Por status</option><option value="product">Produto A–Z</option>';
    const note = document.createElement('span');
    note.id = 'sv-admin-sort-note';
    note.className = 'sv-admin-sort-note';
    wrap.append(label, select, note);
    const count = $('#sv-effective-visible-count', toolbar);
    toolbar.insertBefore(wrap, count || null);
    select.addEventListener('change', () => sortCatalog(select.value));
    window.setTimeout(() => sortCatalog(select.value), 350);
    window.setTimeout(() => sortCatalog(select.value), 1600);
    return true;
  }

  function boot() {
    if (isHome) {
      enhanceHomeSections();
      ensureHomeDock();
      return;
    }
    if (ensureCatalogSort()) return;
    const observer = new MutationObserver(() => {
      if (ensureCatalogSort()) observer.disconnect();
    });
    observer.observe(document.documentElement, { childList: true, subtree: true });
    window.setTimeout(() => observer.disconnect(), 12000);
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot, { once: true });
  else boot();
})();

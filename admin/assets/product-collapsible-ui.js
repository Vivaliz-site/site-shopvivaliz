(() => {
  'use strict';

  const pathname = window.location.pathname.replace(/\/+$/, '') || '/';
  const supported = new Set([
    '/admin/ai-image-studio/admin_dashboard.php',
    '/admin/ai-image-studio/admin_validate.php',
    '/admin/catalog-optimization/admin_catalog.php',
  ]);
  if (!supported.has(pathname)) return;

  const style = document.createElement('style');
  style.textContent = `
    .sv-collapsible-list{display:grid;gap:10px;margin:12px 0}
    details.sv-collapsible-item{display:block;background:#fff;border:1px solid #dfe3e8;border-radius:10px;overflow:hidden;min-width:0}
    details.sv-collapsible-item[open]{box-shadow:0 8px 24px rgba(15,23,42,.08)}
    details.sv-collapsible-item>summary{list-style:none;display:flex;align-items:center;gap:12px;padding:12px 14px;cursor:pointer;min-width:0;background:#fff}
    details.sv-collapsible-item>summary::-webkit-details-marker{display:none}
    details.sv-collapsible-item>summary::after{content:'▾';margin-left:auto;font-size:15px;color:#64748b;transition:transform .15s ease;flex:0 0 auto}
    details.sv-collapsible-item[open]>summary::after{transform:rotate(180deg)}
    .sv-collapsible-body{padding:0 14px 14px;border-top:1px solid #edf0f3;background:#fbfcfe;min-width:0}
    .sv-collapsible-check{display:inline-flex!important;align-items:center!important;gap:7px!important;margin:0!important;font-weight:700!important;flex:0 0 auto}
    .sv-collapsible-check input{transform:scale(1.08);margin:0}
    .sv-collapsible-title{display:grid;gap:2px;min-width:0;flex:1}
    .sv-collapsible-title strong{overflow-wrap:anywhere}
    .sv-collapsible-meta{font-size:12px;color:#64748b;overflow-wrap:anywhere}
    .sv-collapsible-thumb{width:54px;height:54px;object-fit:contain;border-radius:7px;background:#f1f5f9;flex:0 0 auto}
    .sv-collapsible-toolbar{display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin:10px 0}
    .sv-collapsible-toolbar button{border:0;border-radius:7px;padding:8px 12px;font-weight:700;cursor:pointer;background:#eef2f7;color:#111827}
    .sv-collapsible-count{padding:7px 10px;border-radius:7px;background:#f8fafc;border:1px solid #e5e7eb;font-size:13px;color:#334155}
    .sv-result-grid{display:grid;grid-template-columns:minmax(110px,180px) minmax(0,1fr);gap:8px 12px;padding-top:12px}
    .sv-result-grid>strong{font-size:13px;color:#475569}
    .sv-result-value{min-width:0;overflow-wrap:anywhere}
    article.sv-collapsible-host{padding:0!important;border:0!important;background:transparent!important;overflow:visible!important}
    article.sv-collapsible-host>details.sv-collapsible-item{margin:0}
    article.sv-collapsible-host .source-title{flex:1;min-width:0}
    article.sv-collapsible-host .item-select{flex:0 0 auto;margin:0}
    .candidate-list details.candidate-item{display:block;padding:0;background:#fff}
    .candidate-list details.candidate-item>summary{padding:10px 12px}
    .candidate-list details.candidate-item .sv-collapsible-body{padding:8px 12px 12px}
    .candidate-list details.candidate-item .candidate-meta{display:grid}
    .ais-preview-list details.ais-preview-item{display:block;padding:0;background:#fafbfc}
    .ais-preview-list details.ais-preview-item>summary{padding:10px 12px}
    .ais-preview-list details.ais-preview-item .ais-pi-types{padding:12px 0 0;width:100%}
    .ais-preview-list details.ais-preview-item .ais-product-check{margin:0}
    .sv-image-validation-summary .top{flex:1;min-width:0}
    .sv-image-validation-summary .meta{margin:0}
    .sv-image-validation-summary{align-items:flex-start!important}
    .sv-selected-outline{outline:2px solid #1769aa;outline-offset:1px}
    @media(max-width:760px){
      details.sv-collapsible-item>summary{align-items:flex-start;flex-wrap:wrap}
      details.sv-collapsible-item>summary::after{margin-left:0}
      .sv-collapsible-title{width:calc(100% - 44px)}
      .sv-result-grid{grid-template-columns:1fr}
      .ais-preview-list details.ais-preview-item .ais-pi-types{padding-left:0}
      .sv-collapsible-thumb{width:48px;height:48px}
    }
  `;
  document.head.appendChild(style);

  const stopSummaryToggle = (element) => {
    if (!element) return;
    element.addEventListener('click', (event) => event.stopPropagation());
  };

  const selectedOutline = (checkbox, details) => {
    const update = () => details.classList.toggle('sv-selected-outline', checkbox.checked);
    checkbox.addEventListener('change', update);
    update();
  };

  const makeToolbar = (checkboxes, labels = {}) => {
    const toolbar = document.createElement('div');
    toolbar.className = 'sv-collapsible-toolbar';
    const selectAll = document.createElement('button');
    selectAll.type = 'button';
    selectAll.textContent = labels.selectAll || 'Selecionar tudo';
    const clear = document.createElement('button');
    clear.type = 'button';
    clear.textContent = labels.clear || 'Limpar seleção';
    const count = document.createElement('span');
    count.className = 'sv-collapsible-count';

    const update = () => {
      const selected = checkboxes.filter((checkbox) => checkbox.checked).length;
      count.textContent = `Selecionados: ${selected}/${checkboxes.length}`;
    };
    selectAll.addEventListener('click', () => {
      checkboxes.forEach((checkbox) => {
        checkbox.checked = true;
        checkbox.dispatchEvent(new Event('change', { bubbles: true }));
      });
      update();
    });
    clear.addEventListener('click', () => {
      checkboxes.forEach((checkbox) => {
        checkbox.checked = false;
        checkbox.dispatchEvent(new Event('change', { bubbles: true }));
      });
      update();
    });
    checkboxes.forEach((checkbox) => checkbox.addEventListener('change', update));
    toolbar.append(selectAll, clear, count);
    update();
    return toolbar;
  };

  const enhanceImagePreview = () => {
    const list = document.querySelector('.ais-preview-list');
    if (!list || list.dataset.svCollapsible === '1') return;
    list.dataset.svCollapsible = '1';

    [...list.querySelectorAll(':scope > .ais-preview-item')].forEach((item) => {
      const checkboxLabel = item.querySelector('.ais-product-check');
      const checkbox = checkboxLabel?.querySelector('input[data-product-check]');
      const info = item.querySelector('.ais-pi-info');
      const types = item.querySelector('.ais-pi-types');
      const image = item.querySelector('img');
      if (!checkbox || !checkboxLabel || !info || !types) return;

      const details = document.createElement('details');
      details.className = 'sv-collapsible-item ais-preview-item';
      const summary = document.createElement('summary');
      checkboxLabel.classList.add('sv-collapsible-check');
      stopSummaryToggle(checkboxLabel);
      selectedOutline(checkbox, details);

      if (image) image.classList.add('sv-collapsible-thumb');
      summary.append(checkboxLabel);
      if (image) summary.append(image);
      summary.append(info);

      const body = document.createElement('div');
      body.className = 'sv-collapsible-body';
      body.append(types);
      details.append(summary, body);
      item.replaceWith(details);
    });
  };

  const enhanceImageRecentResults = () => {
    const heading = [...document.querySelectorAll('h2')].find((node) => node.textContent.trim() === 'Itens recentes');
    if (!heading) return;
    const tableWrap = heading.nextElementSibling;
    const table = tableWrap?.querySelector('table.ais-table');
    if (!table || tableWrap.dataset.svCollapsible === '1') return;
    const headers = [...table.querySelectorAll('thead th')].map((cell) => cell.textContent.trim());
    const rows = [...table.querySelectorAll('tbody tr')];
    if (!rows.length) return;

    const list = document.createElement('div');
    list.className = 'sv-collapsible-list';
    const checkboxes = [];

    rows.forEach((row) => {
      const cells = [...row.children];
      if (!cells.length) return;
      const details = document.createElement('details');
      details.className = 'sv-collapsible-item';
      const summary = document.createElement('summary');
      const checkboxLabel = document.createElement('label');
      checkboxLabel.className = 'sv-collapsible-check';
      const checkbox = document.createElement('input');
      checkbox.type = 'checkbox';
      checkbox.value = cells[0]?.textContent.trim().replace(/^#/, '') || '';
      checkbox.setAttribute('data-sv-result-check', '1');
      checkboxLabel.append(checkbox, document.createTextNode(' Selecionar'));
      stopSummaryToggle(checkboxLabel);
      selectedOutline(checkbox, details);
      checkboxes.push(checkbox);

      const title = document.createElement('div');
      title.className = 'sv-collapsible-title';
      const strong = document.createElement('strong');
      const product = cells[1]?.textContent.trim() || 'Produto';
      const status = cells[5]?.textContent.trim() || '';
      strong.textContent = `${product}${status ? ` · ${status}` : ''}`;
      const meta = document.createElement('span');
      meta.className = 'sv-collapsible-meta';
      meta.textContent = [cells[2]?.textContent.trim(), cells[3]?.textContent.trim(), cells[4]?.textContent.trim(), cells[7]?.textContent.trim()].filter(Boolean).join(' · ');
      title.append(strong, meta);
      summary.append(checkboxLabel, title);

      const body = document.createElement('div');
      body.className = 'sv-collapsible-body';
      const grid = document.createElement('div');
      grid.className = 'sv-result-grid';
      cells.forEach((cell, index) => {
        const label = document.createElement('strong');
        label.textContent = headers[index] || `Campo ${index + 1}`;
        const value = document.createElement('div');
        value.className = 'sv-result-value';
        while (cell.firstChild) value.appendChild(cell.firstChild);
        grid.append(label, value);
      });
      body.append(grid);
      details.append(summary, body);
      list.append(details);
    });

    if (!checkboxes.length) return;
    tableWrap.dataset.svCollapsible = '1';
    tableWrap.hidden = true;
    heading.insertAdjacentElement('afterend', makeToolbar(checkboxes));
    heading.nextElementSibling.insertAdjacentElement('afterend', list);
  };

  const enhanceCatalogCandidate = (item) => {
    if (!(item instanceof HTMLElement) || item.dataset.svCollapsible === '1') return;
    const checkbox = item.querySelector('input[data-candidate-id]');
    const meta = item.querySelector('.candidate-meta');
    const name = meta?.querySelector('.candidate-name');
    if (!checkbox || !meta || !name) return;

    item.dataset.svCollapsible = '1';
    const details = document.createElement('details');
    details.className = 'candidate-item sv-collapsible-item';
    const summary = document.createElement('summary');
    const checkWrap = document.createElement('label');
    checkWrap.className = 'sv-collapsible-check';
    checkbox.remove();
    checkWrap.append(checkbox, document.createTextNode(' Selecionar'));
    stopSummaryToggle(checkWrap);
    selectedOutline(checkbox, details);

    const title = document.createElement('div');
    title.className = 'sv-collapsible-title';
    const strong = document.createElement('strong');
    strong.textContent = name.textContent.trim();
    const metaLine = document.createElement('span');
    metaLine.className = 'sv-collapsible-meta';
    const all = [...meta.querySelectorAll('span')].map((span) => span.textContent.trim());
    metaLine.textContent = all.slice(1).join(' · ');
    title.append(strong, metaLine);
    summary.append(checkWrap, title);

    name.remove();
    const body = document.createElement('div');
    body.className = 'sv-collapsible-body';
    body.append(meta);
    details.append(summary, body);
    item.replaceWith(details);
  };

  const enhanceCatalogCandidates = () => {
    const list = document.getElementById('candidate-list');
    if (!list || list.dataset.svObserver === '1') return;
    list.dataset.svObserver = '1';
    const run = () => [...list.querySelectorAll(':scope > .candidate-item:not(details)')].forEach(enhanceCatalogCandidate);
    const observer = new MutationObserver(run);
    observer.observe(list, { childList: true });
    run();
  };

  const enhanceCatalogResults = () => {
    const items = [...document.querySelectorAll('article.item')].filter((item) => item.querySelector('input[name="selected_ids[]"][form="bulk-action-form"]'));
    if (!items.length) return;
    const checkboxes = [];

    items.forEach((article) => {
      if (article.dataset.svCollapsible === '1') return;
      const sourceTitle = article.querySelector(':scope > .source-title');
      const select = article.querySelector(':scope > .item-select');
      const checkbox = select?.querySelector('input[name="selected_ids[]"]');
      if (!sourceTitle || !select || !checkbox) return;
      article.dataset.svCollapsible = '1';
      article.classList.add('sv-collapsible-host');

      const quality = article.querySelector('.quality .score')?.textContent.trim() || '';
      const details = document.createElement('details');
      details.className = 'sv-collapsible-item';
      const summary = document.createElement('summary');
      select.classList.add('sv-collapsible-check');
      stopSummaryToggle(select);
      selectedOutline(checkbox, details);
      checkboxes.push(checkbox);
      summary.append(select, sourceTitle);
      if (quality) {
        const qualityBadge = document.createElement('span');
        qualityBadge.className = 'badge';
        qualityBadge.textContent = quality;
        summary.append(qualityBadge);
      }

      const body = document.createElement('div');
      body.className = 'sv-collapsible-body';
      [...article.childNodes].forEach((node) => {
        if (node !== details && node !== summary && node !== sourceTitle && node !== select) body.append(node);
      });
      details.append(summary, body);
      article.append(details);
    });

    const bulkForm = document.getElementById('bulk-action-form');
    const bulkPanel = bulkForm?.closest('.bulk-panel');
    if (bulkPanel && checkboxes.length && !bulkPanel.querySelector('[data-sv-bulk-count]')) {
      const count = document.createElement('span');
      count.className = 'sv-collapsible-count';
      count.setAttribute('data-sv-bulk-count', '1');
      const update = () => {
        count.textContent = `Selecionados nos resultados: ${checkboxes.filter((checkbox) => checkbox.checked).length}/${checkboxes.length}`;
      };
      checkboxes.forEach((checkbox) => checkbox.addEventListener('change', update));
      document.getElementById('bulk-select-all')?.addEventListener('click', () => setTimeout(update, 0));
      document.getElementById('bulk-clear')?.addEventListener('click', () => setTimeout(update, 0));
      bulkPanel.append(count);
      update();
    }
  };

  const enhanceImageValidationResults = () => {
    if (pathname !== '/admin/ai-image-studio/admin_validate.php') return;
    const grid = document.querySelector('main.wrap > section.grid');
    if (!grid || grid.dataset.svCollapsible === '1') return;
    const cards = [...grid.querySelectorAll(':scope > article.card')];
    if (!cards.length) return;
    grid.dataset.svCollapsible = '1';
    const checkboxes = [];

    cards.forEach((article) => {
      const top = article.querySelector(':scope > .top');
      const meta = article.querySelector(':scope > .meta');
      if (!top || !meta) return;
      article.classList.add('sv-collapsible-host');
      const details = document.createElement('details');
      details.className = 'sv-collapsible-item';
      const summary = document.createElement('summary');
      summary.classList.add('sv-image-validation-summary');
      const checkboxLabel = document.createElement('label');
      checkboxLabel.className = 'sv-collapsible-check';
      const checkbox = document.createElement('input');
      checkbox.type = 'checkbox';
      const stagingId = article.querySelector('input[name="staging_id"]')?.value || '';
      checkbox.value = stagingId;
      checkbox.setAttribute('data-sv-validation-check', '1');
      checkboxLabel.append(checkbox, document.createTextNode(' Selecionar'));
      stopSummaryToggle(checkboxLabel);
      selectedOutline(checkbox, details);
      checkboxes.push(checkbox);

      const title = document.createElement('div');
      title.className = 'sv-collapsible-title';
      title.append(top, meta);
      summary.append(checkboxLabel, title);

      const body = document.createElement('div');
      body.className = 'sv-collapsible-body';
      [...article.childNodes].forEach((node) => {
        if (node !== top && node !== meta && node !== details && node !== summary) body.append(node);
      });
      details.append(summary, body);
      article.append(details);
    });

    if (checkboxes.length) grid.insertAdjacentElement('beforebegin', makeToolbar(checkboxes));
  };

  if (pathname === '/admin/ai-image-studio/admin_dashboard.php') {
    enhanceImagePreview();
    enhanceImageRecentResults();
  }
  if (pathname === '/admin/ai-image-studio/admin_validate.php') enhanceImageValidationResults();
  if (pathname === '/admin/catalog-optimization/admin_catalog.php') {
    enhanceCatalogCandidates();
    enhanceCatalogResults();
  }
})();

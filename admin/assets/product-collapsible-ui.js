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
    details.sv-collapsible-item{display:block;background:#fff;border:1px solid #dfe3e8;border-radius:10px;overflow:hidden;min-width:0;transition:border-color .15s ease,box-shadow .15s ease}
    details.sv-collapsible-item[open]{box-shadow:0 8px 24px rgba(15,23,42,.08)}
    details.sv-collapsible-item>summary{list-style:none;display:flex;align-items:center;gap:12px;padding:12px 14px;cursor:pointer;min-width:0;background:#fff}
    details.sv-collapsible-item>summary::-webkit-details-marker{display:none}
    details.sv-collapsible-item>summary::after{content:'▾';margin-left:auto;font-size:15px;color:#64748b;transition:transform .15s ease;flex:0 0 auto}
    details.sv-collapsible-item[open]>summary::after{transform:rotate(180deg)}
    .sv-collapsible-body{padding:12px 14px 14px;border-top:1px solid #edf0f3;background:#fbfcfe;min-width:0}
    .sv-collapsible-check{display:inline-flex!important;align-items:center!important;gap:7px!important;margin:0!important;padding:8px 10px!important;font-weight:800!important;flex:0 0 auto;border:1px solid #cbd5e1;border-radius:8px;background:#f8fafc;cursor:pointer}
    .sv-collapsible-check:hover{background:#eef6ff;border-color:#93c5fd}
    .sv-collapsible-check input{width:18px;height:18px;margin:0;accent-color:#1769aa;cursor:pointer}
    .sv-collapsible-title{display:grid;gap:2px;min-width:0;flex:1}
    .sv-collapsible-title strong{overflow-wrap:anywhere}
    .sv-collapsible-meta{font-size:12px;color:#64748b;overflow-wrap:anywhere}
    .sv-collapsible-thumb{width:54px;height:54px;object-fit:contain;border-radius:7px;background:#f1f5f9;flex:0 0 auto}
    .sv-collapsible-toolbar{display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin:10px 0}
    .sv-collapsible-toolbar button,.sv-refresh-button{border:0;border-radius:7px;padding:8px 12px;font-weight:800;cursor:pointer;background:#eef2f7;color:#111827}
    .sv-collapsible-toolbar button:hover,.sv-refresh-button:hover{background:#e2e8f0}
    .sv-collapsible-count{padding:7px 10px;border-radius:7px;background:#f8fafc;border:1px solid #e5e7eb;font-size:13px;color:#334155}
    .sv-action-status{display:inline-flex;align-items:center;gap:5px;font-size:12px;font-weight:800;border-radius:999px;padding:4px 8px;background:#f1f5f9;color:#475569;white-space:nowrap}
    .sv-action-status.working{background:#dbeafe;color:#1d4ed8}
    .sv-action-status.ok{background:#dcfce7;color:#166534}
    .sv-action-status.warn{background:#fef3c7;color:#854d0e}
    .sv-action-status.error{background:#fee2e2;color:#991b1b}
    .sv-action-message{padding:10px 12px;margin:10px 0;border-radius:8px;border:1px solid #dbe3ed;background:#f8fafc;color:#334155;font-size:14px}
    .sv-action-message.ok{background:#ecfdf5;border-color:#bbf7d0;color:#166534}
    .sv-action-message.error{background:#fef2f2;border-color:#fecaca;color:#991b1b}
    .sv-result-grid{display:grid;grid-template-columns:minmax(110px,180px) minmax(0,1fr);gap:8px 12px}
    .sv-result-grid>strong{font-size:13px;color:#475569}
    .sv-result-value{min-width:0;overflow-wrap:anywhere}
    article.sv-collapsible-host{padding:0!important;border:0!important;background:transparent!important;overflow:visible!important}
    article.sv-collapsible-host>details.sv-collapsible-item{margin:0}
    article.sv-collapsible-host .source-title{flex:1;min-width:0}
    article.sv-collapsible-host .item-select{flex:0 0 auto;margin:0}
    .candidate-list details.candidate-item{display:block;padding:0;background:#fff}
    .candidate-list details.candidate-item>summary{padding:10px 12px}
    .candidate-list details.candidate-item .candidate-meta{display:grid;gap:4px}
    .ais-preview-list details.ais-preview-item{display:block;padding:0;background:#fafbfc}
    .ais-preview-list details.ais-preview-item>summary{padding:10px 12px}
    .ais-preview-list details.ais-preview-item .ais-pi-types{padding:0;width:100%}
    .ais-preview-list details.ais-preview-item .ais-product-check{margin:0}
    .sv-image-validation-summary .top{flex:1;min-width:0}
    .sv-image-validation-summary .meta{margin:0}
    .sv-image-validation-summary{align-items:flex-start!important}
    .sv-selected-outline{border-color:#1769aa!important;box-shadow:0 0 0 2px rgba(23,105,170,.12)}
    .sv-queued-outline{border-color:#16a34a!important}
    .sv-failed-outline{border-color:#dc2626!important}
    .sv-actions-busy{opacity:.7;pointer-events:none}
    @media(max-width:760px){
      details.sv-collapsible-item>summary{align-items:flex-start;flex-wrap:wrap}
      details.sv-collapsible-item>summary::after{margin-left:0}
      .sv-collapsible-title{width:calc(100% - 44px)}
      .sv-result-grid{grid-template-columns:1fr}
      .ais-preview-list details.ais-preview-item .ais-pi-types{padding-left:0}
      .sv-collapsible-thumb{width:48px;height:48px}
      .sv-action-status{white-space:normal}
    }
  `;
  document.head.appendChild(style);

  const interactiveSelector = 'input,button,a,select,textarea,label';

  function shieldInteractive(root) {
    if (!root) return;
    root.querySelectorAll(interactiveSelector).forEach((element) => {
      element.addEventListener('click', (event) => event.stopPropagation());
    });
  }

  function selectedOutline(checkbox, details) {
    const update = () => details.classList.toggle('sv-selected-outline', checkbox.checked);
    checkbox.addEventListener('change', update);
    update();
  }

  function statusFor(details) {
    let node = details.querySelector(':scope > summary .sv-action-status');
    if (!node) {
      node = document.createElement('span');
      node.className = 'sv-action-status';
      node.textContent = 'Pronto';
      details.querySelector(':scope > summary')?.append(node);
    }
    return node;
  }

  function setStatus(details, text, state = '') {
    const node = statusFor(details);
    node.className = `sv-action-status${state ? ` ${state}` : ''}`;
    node.textContent = text;
    details.classList.toggle('sv-queued-outline', state === 'ok');
    details.classList.toggle('sv-failed-outline', state === 'error');
  }

  function messageBox(anchor) {
    const parent = anchor?.parentElement || document.body;
    let box = parent.querySelector(':scope > .sv-action-message');
    if (!box) {
      box = document.createElement('div');
      box.className = 'sv-action-message';
      anchor?.insertAdjacentElement('afterend', box);
    }
    return box;
  }

  function showMessage(anchor, text, state = '') {
    const box = messageBox(anchor);
    box.className = `sv-action-message${state ? ` ${state}` : ''}`;
    box.textContent = text;
    return box;
  }

  async function readJsonResponse(response) {
    if (response.redirected || /\/auth\/login\.php/i.test(response.url || '')) {
      throw new Error('Sua sessao administrativa expirou. Entre novamente e repita a operacao.');
    }
    const contentType = response.headers.get('content-type') || '';
    const text = await response.text();
    if (!contentType.includes('application/json')) {
      const compact = text.replace(/\s+/g, ' ').trim().slice(0, 180);
      throw new Error(compact ? `Resposta inesperada do servidor: ${compact}` : `Resposta HTTP ${response.status}.`);
    }
    let data;
    try {
      data = JSON.parse(text);
    } catch (error) {
      throw new Error(`O servidor retornou JSON invalido (HTTP ${response.status}).`);
    }
    if (!response.ok && !data?.error) {
      data.error = `HTTP ${response.status}`;
    }
    return data;
  }

  function makeToolbar(checkboxes, detailsList, options = {}) {
    const toolbar = document.createElement('div');
    toolbar.className = 'sv-collapsible-toolbar';
    const selectAll = document.createElement('button');
    selectAll.type = 'button';
    selectAll.textContent = options.selectAll || 'Selecionar tudo';
    const clear = document.createElement('button');
    clear.type = 'button';
    clear.textContent = options.clear || 'Limpar selecao';
    const openSelected = document.createElement('button');
    openSelected.type = 'button';
    openSelected.textContent = 'Abrir selecionados';
    const closeAll = document.createElement('button');
    closeAll.type = 'button';
    closeAll.textContent = 'Fechar todos';
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
    openSelected.addEventListener('click', () => {
      detailsList.forEach((details, index) => {
        if (checkboxes[index]?.checked) details.open = true;
      });
    });
    closeAll.addEventListener('click', () => detailsList.forEach((details) => { details.open = false; }));
    checkboxes.forEach((checkbox) => checkbox.addEventListener('change', update));
    toolbar.append(selectAll, clear, openSelected, closeAll, count);
    update();
    return toolbar;
  }

  function enhanceImagePreview() {
    const list = document.querySelector('.ais-preview-list');
    if (!list || list.dataset.svCollapsible === '1') return;
    list.dataset.svCollapsible = '1';
    const checkboxes = [];
    const detailsList = [];

    [...list.querySelectorAll(':scope > .ais-preview-item')].forEach((item) => {
      const checkboxLabel = item.querySelector('.ais-product-check');
      const checkbox = checkboxLabel?.querySelector('input[data-product-check]');
      const info = item.querySelector('.ais-pi-info');
      const types = item.querySelector('.ais-pi-types');
      const image = item.querySelector('img');
      if (!checkbox || !checkboxLabel || !info || !types) return;

      const details = document.createElement('details');
      details.className = 'sv-collapsible-item ais-preview-item';
      details.dataset.productId = checkbox.value;
      const summary = document.createElement('summary');
      checkboxLabel.classList.add('sv-collapsible-check');
      checkboxLabel.childNodes.forEach((node) => {
        if (node.nodeType === Node.TEXT_NODE && node.textContent?.trim()) node.textContent = ' Selecionar';
      });
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
      shieldInteractive(summary);
      statusFor(details).textContent = 'Nao selecionado';

      // A tela antiga marcava todos por padrao. Agora a selecao e explicita,
      // evitando gerar um lote inteiro quando o usuario quer apenas um item.
      checkbox.checked = false;
      checkbox.dispatchEvent(new Event('change', { bubbles: true }));
      checkboxes.push(checkbox);
      detailsList.push(details);
    });

    const existingActions = document.querySelector('.ais-preview-actions');
    if (existingActions && !existingActions.querySelector('[data-sv-open-selected]')) {
      const openSelected = document.createElement('button');
      openSelected.type = 'button';
      openSelected.dataset.svOpenSelected = '1';
      openSelected.textContent = 'Abrir selecionados';
      openSelected.addEventListener('click', () => detailsList.forEach((details, i) => { if (checkboxes[i]?.checked) details.open = true; }));
      const closeAll = document.createElement('button');
      closeAll.type = 'button';
      closeAll.textContent = 'Fechar todos';
      closeAll.addEventListener('click', () => detailsList.forEach((details) => { details.open = false; }));
      existingActions.append(openSelected, closeAll);
    }

    const form = list.closest('form');
    const submit = form?.querySelector('#ais-submit');
    if (!form || !submit || form.dataset.svReliableSubmit === '1') return;
    form.dataset.svReliableSubmit = '1';

    form.addEventListener('submit', async (event) => {
      event.preventDefault();
      event.stopImmediatePropagation();
      const selected = checkboxes.filter((checkbox) => checkbox.checked);
      if (!selected.length) {
        showMessage(existingActions || list, 'Selecione ao menos um produto. Nenhum item e gerado automaticamente.', 'error');
        return;
      }

      const provider = form.querySelector('input[name="provider"]')?.value || '';
      const model = form.querySelector('input[name="model"]')?.value || '';
      const targetChannel = form.querySelector('input[name="target_channel"]')?.value || '';
      const oldText = submit.textContent;
      submit.disabled = true;
      submit.textContent = `Enfileirando ${selected.length} produto(s)...`;
      form.classList.add('sv-actions-busy');
      let queued = 0;
      let failed = 0;

      for (const checkbox of selected) {
        const details = checkbox.closest('details.sv-collapsible-item');
        if (!details) continue;
        const imageTypes = [...details.querySelectorAll('input[name^="image_types["]:checked')].map((input) => input.value);
        if (!imageTypes.length) {
          failed += 1;
          setStatus(details, 'Marque um tipo de imagem', 'error');
          details.open = true;
          continue;
        }

        setStatus(details, 'Validando foto e fila...', 'working');
        try {
          const response = await fetch('/admin/ai-image-studio/api/enqueue_generation.php', {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
            body: JSON.stringify({
              product_id: Number(checkbox.value),
              provider,
              model,
              target_channel: targetChannel,
              image_types: imageTypes,
            }),
          });
          const data = await readJsonResponse(response);
          if (!data.success) throw new Error(data.error || 'Falha ao enfileirar.');
          queued += 1;
          setStatus(details, `Fila #${data.job_id}`, 'ok');
          checkbox.checked = false;
          checkbox.dispatchEvent(new Event('change', { bubbles: true }));
        } catch (error) {
          failed += 1;
          details.open = true;
          setStatus(details, error.message || 'Falha', 'error');
        }
      }

      form.classList.remove('sv-actions-busy');
      submit.disabled = false;
      submit.textContent = oldText;
      const text = failed
        ? `${queued} produto(s) enfileirado(s) e ${failed} com falha. Abra os itens em vermelho para ver o motivo.`
        : `${queued} produto(s) enfileirado(s). O processamento ocorre em segundo plano; atualize a pagina em alguns instantes para ver os resultados.`;
      const box = showMessage(existingActions || list, text, failed ? 'error' : 'ok');
      if (queued) {
        const refresh = document.createElement('button');
        refresh.type = 'button';
        refresh.className = 'sv-refresh-button';
        refresh.textContent = 'Atualizar resultados';
        refresh.addEventListener('click', () => window.location.reload());
        box.append(document.createTextNode(' '), refresh);
      }
    });
  }

  function enhanceImageRecentResults() {
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
    const detailsList = [];

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
      checkboxLabel.append(checkbox, document.createTextNode(' Revisar'));
      selectedOutline(checkbox, details);

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
      shieldInteractive(summary);
      checkboxes.push(checkbox);
      detailsList.push(details);
    });

    if (!checkboxes.length) return;
    tableWrap.dataset.svCollapsible = '1';
    tableWrap.hidden = true;
    const toolbar = makeToolbar(checkboxes, detailsList, { selectAll: 'Marcar todos para revisar' });
    heading.insertAdjacentElement('afterend', toolbar);
    toolbar.insertAdjacentElement('afterend', list);
  }

  function enhanceCatalogCandidate(item) {
    if (!(item instanceof HTMLElement) || item.dataset.svCollapsible === '1') return;
    const checkbox = item.querySelector('input[data-candidate-id]');
    const meta = item.querySelector('.candidate-meta');
    const name = meta?.querySelector('.candidate-name');
    if (!checkbox || !meta || !name) return;

    item.dataset.svCollapsible = '1';
    const details = document.createElement('details');
    details.className = 'candidate-item sv-collapsible-item';
    details.dataset.productId = checkbox.value;
    const summary = document.createElement('summary');
    const checkWrap = document.createElement('label');
    checkWrap.className = 'sv-collapsible-check';
    checkbox.remove();
    checkWrap.append(checkbox, document.createTextNode(' Selecionar'));
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
    shieldInteractive(summary);
    statusFor(details).textContent = 'Nao selecionado';
  }

  function enhanceCatalogCandidates() {
    const list = document.getElementById('candidate-list');
    if (!list || list.dataset.svObserver === '1') return;
    list.dataset.svObserver = '1';
    const run = () => [...list.querySelectorAll(':scope > .candidate-item:not(details)')].forEach(enhanceCatalogCandidate);
    const observer = new MutationObserver(run);
    observer.observe(list, { childList: true });
    run();
  }

  function enhanceCatalogRunAction() {
    const runBtn = document.getElementById('run');
    const list = document.getElementById('candidate-list');
    const channelEl = document.getElementById('channel');
    const providerEl = document.getElementById('provider');
    const log = document.getElementById('log');
    if (!runBtn || !list || !channelEl || !providerEl || runBtn.dataset.svReliableRun === '1') return;
    runBtn.dataset.svReliableRun = '1';

    const write = (text) => {
      if (!log) return;
      log.style.display = 'block';
      log.textContent += `${text}\n`;
      log.scrollTop = log.scrollHeight;
    };

    runBtn.addEventListener('click', async (event) => {
      event.preventDefault();
      event.stopImmediatePropagation();
      const selected = [...list.querySelectorAll('input[data-candidate-id]:checked')];
      if (!selected.length) {
        write('Selecione ao menos um item. A geracao nao escolhe produtos automaticamente.');
        showMessage(list, 'Selecione ao menos um produto para otimizar.', 'error');
        return;
      }

      const oldText = runBtn.textContent;
      runBtn.disabled = true;
      runBtn.textContent = `Gerando ${selected.length} rascunho(s)...`;
      let generated = 0;
      let failed = 0;
      if (log) log.textContent = '';

      for (const checkbox of selected) {
        const details = checkbox.closest('details.sv-collapsible-item');
        const id = Number(checkbox.value);
        if (details) setStatus(details, 'Gerando rascunho...', 'working');
        write(`#${id}: iniciando...`);
        try {
          const response = await fetch('/admin/catalog-optimization/api/optimize_catalog_resilient.php', {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
            body: JSON.stringify({ product_id: id, target_channel: channelEl.value, provider: providerEl.value }),
          });
          const data = await readJsonResponse(response);
          if (!data.success) throw new Error(data.error || 'Falha ao gerar rascunho.');
          generated += 1;
          const suffix = data.needs_review ? ` · revisar quality ${data.quality_score}/100` : ` · quality ${data.quality_score}/100`;
          if (details) setStatus(details, `Gerado${suffix}`, data.needs_review ? 'warn' : 'ok');
          checkbox.checked = false;
          checkbox.dispatchEvent(new Event('change', { bubbles: true }));
          write(`#${id}: gerado com ${data.provider_used}${suffix}.`);
        } catch (error) {
          failed += 1;
          if (details) {
            details.open = true;
            setStatus(details, error.message || 'Falha', 'error');
          }
          write(`#${id}: FALHA - ${error.message || error}`);
        }
      }

      runBtn.disabled = false;
      runBtn.textContent = oldText;
      const text = failed
        ? `${generated} rascunho(s) gerado(s) e ${failed} falha(s). Os itens com erro ficaram abertos.`
        : `${generated} rascunho(s) gerado(s). Mesmo rascunhos com alertas continuam bloqueados para publicacao ate o quality gate passar.`;
      const box = showMessage(list, text, failed ? 'error' : 'ok');
      if (generated) {
        const refresh = document.createElement('button');
        refresh.type = 'button';
        refresh.className = 'sv-refresh-button';
        refresh.textContent = 'Atualizar resultados';
        refresh.addEventListener('click', () => window.location.reload());
        box.append(document.createTextNode(' '), refresh);
      }
    }, true);
  }

  function enhanceCatalogResults() {
    const items = [...document.querySelectorAll('article.item')].filter((item) => item.querySelector('input[name="selected_ids[]"][form="bulk-action-form"]'));
    if (!items.length) return;
    const checkboxes = [];
    const detailsList = [];

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
      selectedOutline(checkbox, details);
      checkboxes.push(checkbox);
      detailsList.push(details);
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
      shieldInteractive(summary);
    });

    const bulkForm = document.getElementById('bulk-action-form');
    const bulkPanel = bulkForm?.closest('.bulk-panel');
    if (bulkPanel && checkboxes.length && !bulkPanel.querySelector('[data-sv-results-toolbar]')) {
      const toolbar = makeToolbar(checkboxes, detailsList);
      toolbar.dataset.svResultsToolbar = '1';
      bulkPanel.append(toolbar);
    }
  }

  function enhanceImageValidationResults() {
    if (pathname !== '/admin/ai-image-studio/admin_validate.php') return;
    const grid = document.querySelector('main.wrap > section.grid');
    if (!grid || grid.dataset.svCollapsible === '1') return;
    const cards = [...grid.querySelectorAll(':scope > article.card')];
    if (!cards.length) return;
    grid.dataset.svCollapsible = '1';
    const checkboxes = [];
    const detailsList = [];

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
      checkbox.value = article.querySelector('input[name="staging_id"]')?.value || '';
      checkbox.setAttribute('data-sv-validation-check', '1');
      checkboxLabel.append(checkbox, document.createTextNode(' Revisar'));
      selectedOutline(checkbox, details);
      checkboxes.push(checkbox);
      detailsList.push(details);

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
      shieldInteractive(summary);
    });

    if (checkboxes.length) grid.insertAdjacentElement('beforebegin', makeToolbar(checkboxes, detailsList, { selectAll: 'Marcar todos para revisar' }));
  }

  if (pathname === '/admin/ai-image-studio/admin_dashboard.php') {
    enhanceImagePreview();
    enhanceImageRecentResults();
  }
  if (pathname === '/admin/ai-image-studio/admin_validate.php') enhanceImageValidationResults();
  if (pathname === '/admin/catalog-optimization/admin_catalog.php') {
    enhanceCatalogCandidates();
    enhanceCatalogRunAction();
    enhanceCatalogResults();
  }
})();

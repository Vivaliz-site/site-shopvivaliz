(() => {
  'use strict';

  const path = window.location.pathname.replace(/\/+$/, '') || '/';
  const imageDashboard = '/admin/ai-image-studio/admin_dashboard.php';
  const imageValidate = '/admin/ai-image-studio/admin_validate.php';
  const catalogAdmin = '/admin/catalog-optimization/admin_catalog.php';
  if (![imageDashboard, imageValidate, catalogAdmin].includes(path)) return;

  const css = document.createElement('style');
  css.textContent = `
    .sv-ai-list{display:grid;gap:9px;margin:12px 0}
    details.sv-ai-item{background:#fff;border:1px solid #dfe3e8;border-radius:10px;overflow:hidden;min-width:0}
    details.sv-ai-item[open]{box-shadow:0 8px 24px rgba(15,23,42,.08)}
    details.sv-ai-item>summary{list-style:none;display:flex;align-items:center;gap:10px;padding:11px 13px;cursor:pointer;min-width:0}
    details.sv-ai-item>summary::-webkit-details-marker{display:none}
    details.sv-ai-item>summary::after{content:'▾';margin-left:auto;color:#64748b;flex:0 0 auto}
    details.sv-ai-item[open]>summary::after{transform:rotate(180deg)}
    .sv-ai-body{padding:12px 14px;border-top:1px solid #edf0f3;background:#fbfcfe}
    .sv-ai-check{display:flex!important;align-items:center!important;gap:7px!important;margin:0!important;font-weight:800!important;flex:0 0 auto}
    .sv-ai-check input{width:18px;height:18px;margin:0;accent-color:#1769aa}
    .sv-ai-title{display:grid;gap:2px;min-width:0;flex:1}
    .sv-ai-title strong{overflow-wrap:anywhere}
    .sv-ai-meta{font-size:12px;color:#64748b;overflow-wrap:anywhere}
    .sv-ai-types{display:flex;gap:12px;flex-wrap:wrap}
    .sv-ai-types label{display:flex!important;align-items:center!important;gap:5px!important;margin:0!important;font-weight:500!important}
    .sv-ai-toolbar{display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin:10px 0}
    .sv-ai-toolbar button{border:0;border-radius:7px;padding:8px 12px;font-weight:800;cursor:pointer;background:#eef2f7;color:#111827}
    .sv-ai-toolbar .primary{background:#1769aa;color:#fff}
    .sv-ai-count{font-size:13px;padding:7px 10px;border:1px solid #e2e8f0;border-radius:7px;background:#f8fafc}
    .sv-ai-message{padding:10px 12px;border-radius:8px;margin:10px 0;background:#eef6ff;color:#0c3b57;border:1px solid #bfdbfe}
    .sv-ai-message.error{background:#fef2f2;color:#991b1b;border-color:#fecaca}
    .sv-ai-message.ok{background:#ecfdf5;color:#166534;border-color:#bbf7d0}
    .sv-ai-selected{border-color:#1769aa!important;box-shadow:0 0 0 2px rgba(23,105,170,.12)}
    article.sv-ai-host{padding:0!important;border:0!important;background:transparent!important}
    article.sv-ai-host>details{margin:0}
    @media(max-width:760px){details.sv-ai-item>summary{align-items:flex-start;flex-wrap:wrap}.sv-ai-title{width:100%}}
  `;
  document.head.appendChild(css);

  async function jsonFetch(url, options = {}) {
    const response = await fetch(url, { credentials: 'same-origin', ...options });
    if (response.redirected || /\/auth\/login\.php/i.test(response.url || '')) {
      throw new Error('Sessao administrativa expirada. Entre novamente.');
    }
    const text = await response.text();
    let data;
    try { data = JSON.parse(text); } catch (_) { throw new Error(`Resposta invalida do servidor (HTTP ${response.status}).`); }
    if (!response.ok || data?.success === false) throw new Error(data?.error || `HTTP ${response.status}`);
    return data;
  }

  function setMessage(container, text, state = '') {
    let box = container.querySelector(':scope > .sv-ai-message');
    if (!box) {
      box = document.createElement('div');
      box.className = 'sv-ai-message';
      container.prepend(box);
    }
    box.className = `sv-ai-message${state ? ` ${state}` : ''}`;
    box.textContent = text;
  }

  function shield(summary) {
    summary.querySelectorAll('input,button,a,label,select').forEach((node) => {
      node.addEventListener('click', (event) => event.stopPropagation());
    });
  }

  function updateOutline(details, checkbox) {
    const refresh = () => details.classList.toggle('sv-ai-selected', checkbox.checked);
    checkbox.addEventListener('change', refresh);
    refresh();
  }

  async function renderImageCandidates(form, values) {
    form.innerHTML = '<h2>Passo 2 — Selecionar produtos</h2><div class="sv-ai-message">Carregando produtos elegiveis...</div>';
    try {
      const query = new URLSearchParams({ target_channel: values.targetChannel, limit: String(values.limit) });
      const data = await jsonFetch(`/admin/ai-image-studio/api/pending_candidates.php?${query}`);
      const items = Array.isArray(data.items) ? data.items : [];
      form.innerHTML = '';
      const heading = document.createElement('h2');
      heading.textContent = 'Passo 2 — Selecionar produtos';
      const intro = document.createElement('p');
      intro.textContent = `${items.length} produto(s) disponivel(is). Nenhum produto e marcado automaticamente.`;
      const toolbar = document.createElement('div');
      toolbar.className = 'sv-ai-toolbar';
      const selectAll = document.createElement('button'); selectAll.type = 'button'; selectAll.textContent = 'Selecionar tudo';
      const clear = document.createElement('button'); clear.type = 'button'; clear.textContent = 'Limpar';
      const generate = document.createElement('button'); generate.type = 'button'; generate.className = 'primary'; generate.textContent = 'Gerar selecionados';
      const count = document.createElement('span'); count.className = 'sv-ai-count';
      const back = document.createElement('a'); back.href = '/admin/ai-image-studio/admin_dashboard.php'; back.textContent = 'Trocar canal/IA';
      toolbar.append(selectAll, clear, generate, count, back);
      const list = document.createElement('div'); list.className = 'sv-ai-list';
      const checkboxes = [];

      items.forEach((item) => {
        const details = document.createElement('details'); details.className = 'sv-ai-item';
        const summary = document.createElement('summary');
        const label = document.createElement('label'); label.className = 'sv-ai-check';
        const checkbox = document.createElement('input'); checkbox.type = 'checkbox'; checkbox.value = String(item.id); checkbox.dataset.svImageProduct = '1';
        label.append(checkbox, document.createTextNode(' Selecionar'));
        const title = document.createElement('div'); title.className = 'sv-ai-title';
        const strong = document.createElement('strong'); strong.textContent = item.name || `Produto #${item.id}`;
        const meta = document.createElement('span'); meta.className = 'sv-ai-meta';
        meta.textContent = `#${item.id}${item.sku ? ` · SKU ${item.sku}` : ''}${item.category ? ` · ${item.category}` : ''}${item.has_image ? ' · foto cadastrada' : ' · foto sera validada antes da fila'}`;
        title.append(strong, meta); summary.append(label, title);
        const body = document.createElement('div'); body.className = 'sv-ai-body';
        const types = document.createElement('div'); types.className = 'sv-ai-types';
        [['white','Branco / capa'],['hero','Hero comercial'],['ambient','Ambientada / lifestyle']].forEach(([value, text]) => {
          const typeLabel = document.createElement('label'); const input = document.createElement('input'); input.type = 'checkbox'; input.value = value; input.dataset.svImageType = '1'; input.checked = true; typeLabel.append(input, document.createTextNode(` ${text}`)); types.append(typeLabel);
        });
        body.append(types); details.append(summary, body); list.append(details); shield(summary); updateOutline(details, checkbox); checkboxes.push(checkbox);
      });

      const refreshCount = () => { count.textContent = `Selecionados: ${checkboxes.filter((c) => c.checked).length}/${checkboxes.length}`; };
      checkboxes.forEach((c) => c.addEventListener('change', refreshCount)); refreshCount();
      selectAll.addEventListener('click', () => { checkboxes.forEach((c) => { c.checked = true; c.dispatchEvent(new Event('change', { bubbles: true })); }); });
      clear.addEventListener('click', () => { checkboxes.forEach((c) => { c.checked = false; c.dispatchEvent(new Event('change', { bubbles: true })); }); });
      generate.addEventListener('click', async () => {
        const selected = checkboxes.filter((c) => c.checked);
        if (!selected.length) { setMessage(form, 'Selecione ao menos um produto.', 'error'); return; }
        generate.disabled = true; let queued = 0; const errors = [];
        for (const checkbox of selected) {
          if (queued + errors.length > 0) await new Promise((r) => setTimeout(r, 600));
          const details = checkbox.closest('details');
          const imageTypes = [...details.querySelectorAll('[data-sv-image-type]:checked')].map((node) => node.value);
          if (!imageTypes.length) { errors.push(`#${checkbox.value}: marque um tipo de imagem`); continue; }
          try {
            await jsonFetch('/admin/ai-image-studio/api/enqueue_generation.php', {
              method: 'POST',
              headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
              body: JSON.stringify({ product_id: Number(checkbox.value), provider: values.provider, model: values.model, target_channel: values.targetChannel, image_types: imageTypes }),
            });
            queued += 1;
          } catch (error) { errors.push(`#${checkbox.value}: ${error.message}`); details.open = true; }
        }
        generate.disabled = false;
        setMessage(form, errors.length ? `${queued} enfileirado(s). Falhas: ${errors.join(' | ')}` : `${queued} produto(s) enfileirado(s) com sucesso.`, errors.length ? 'error' : 'ok');
      });

      form.append(heading, intro, toolbar, list);
      if (!items.length) setMessage(form, 'Nenhum produto elegivel para este canal no momento.');
    } catch (error) {
      setMessage(form, error.message, 'error');
    }
  }

  function setupImageDashboard() {
    const stepForm = [...document.querySelectorAll('.ais-form form')].find((form) => form.querySelector('input[name="preview"]'));
    if (stepForm) {
      stepForm.addEventListener('submit', (event) => {
        event.preventDefault(); event.stopImmediatePropagation();
        const values = {
          targetChannel: stepForm.querySelector('[name="target_channel"]')?.value || 'site',
          provider: stepForm.querySelector('[name="provider"]')?.value || 'openai',
          model: stepForm.querySelector('[name="model"]')?.value || '',
          limit: Math.max(0, Math.min(5000, Number(stepForm.querySelector('[name="page_size"], [name="limit"]')?.value || 0))),
        };
        renderImageCandidates(stepForm.closest('.ais-form'), values);
      }, true);
    }

    const params = new URLSearchParams(window.location.search);
    if (params.get('preview') === '1' && !document.querySelector('.ais-preview-list')) {
      const host = document.querySelector('.ais-form');
      if (host) renderImageCandidates(host, {
        targetChannel: params.get('target_channel') || 'site', provider: params.get('provider') || 'openai', model: params.get('model') || '', limit: Math.max(0, Math.min(5000, Number(params.get('limit') || 12))),
      });
    }
  }

  async function loadCatalogCandidates() {
    const list = document.getElementById('candidate-list');
    if (!list) return;
    const channel = document.getElementById('channel')?.value || 'ml';
    const limit = Math.max(0, Math.min(5000, Number(document.getElementById('load-limit')?.value || 200)));
    list.innerHTML = '<div class="sv-ai-meta">Carregando itens unicos...</div>';
    try {
      const data = await jsonFetch(`/admin/catalog-optimization/api/pending_candidates_unique.php?${new URLSearchParams({ channel, limit: String(limit) })}`);
      const items = Array.isArray(data.items) ? data.items : [];
      list.innerHTML = '';
      const seen = new Set();
      items.forEach((item) => {
        const id = String(item.id || ''); if (!id || seen.has(id)) return; seen.add(id);
        const details = document.createElement('details'); details.className = 'sv-ai-item candidate-item';
        const summary = document.createElement('summary');
        const label = document.createElement('label'); label.className = 'sv-ai-check';
        const checkbox = document.createElement('input'); checkbox.type = 'checkbox'; checkbox.value = id; checkbox.dataset.candidateId = id;
        label.append(checkbox, document.createTextNode(' Selecionar'));
        const title = document.createElement('div'); title.className = 'sv-ai-title candidate-meta';
        const strong = document.createElement('strong'); strong.className = 'candidate-name'; strong.textContent = `#${id} · ${item.name || 'Produto'}`;
        const meta = document.createElement('span'); meta.className = 'sv-ai-meta'; meta.textContent = `SKU: ${item.sku || '(sem SKU)'} · Prioridade: ${item.priority_score ?? 0}`;
        title.append(strong, meta); summary.append(label, title);
        const body = document.createElement('div'); body.className = 'sv-ai-body'; body.textContent = `${Number(item.has_image) ? 'com foto' : 'sem foto'} · ${Number(item.has_description) ? 'com descricao' : 'sem descricao'} · ${Number(item.has_sku) ? 'com SKU' : 'sem SKU'}`;
        details.append(summary, body); list.append(details); shield(summary); updateOutline(details, checkbox);
      });
      const summary = document.getElementById('candidate-summary');
      if (summary) summary.textContent = `Fila carregada: ${seen.size} produto(s) unico(s).`;
    } catch (error) { list.innerHTML = `<div class="sv-ai-message error"></div>`; list.firstElementChild.textContent = error.message; }
  }

  function collapseCatalogResults() {
    document.querySelectorAll('article.item').forEach((article) => {
      if (article.classList.contains('sv-ai-host')) return;
      const checkbox = article.querySelector('input[name="selected_ids[]"][form="bulk-action-form"]');
      const title = article.querySelector('.source-title');
      if (!checkbox || !title) return;
      article.classList.add('sv-ai-host');
      const details = document.createElement('details'); details.className = 'sv-ai-item';
      const summary = document.createElement('summary');
      const label = checkbox.closest('label'); if (label) label.classList.add('sv-ai-check');
      if (label) summary.append(label); summary.append(title);
      const body = document.createElement('div'); body.className = 'sv-ai-body';
      while (article.firstChild) body.append(article.firstChild);
      details.append(summary, body); article.append(details); shield(summary); updateOutline(details, checkbox);
    });
  }

  function setupCatalog() {
    document.addEventListener('click', (event) => {
      const button = event.target.closest('#load-candidates');
      if (!button) return;
      event.preventDefault(); event.stopImmediatePropagation(); loadCatalogCandidates();
    }, true);
    setTimeout(loadCatalogCandidates, 0);
    setTimeout(collapseCatalogResults, 0);
  }

  function setupValidation() {
    document.querySelectorAll('main.wrap section.grid > article.card').forEach((article) => {
      if (article.classList.contains('sv-ai-host')) return;
      const top = article.querySelector('.top'); if (!top) return;
      article.classList.add('sv-ai-host'); const details = document.createElement('details'); details.className = 'sv-ai-item';
      const summary = document.createElement('summary'); summary.append(top);
      const body = document.createElement('div'); body.className = 'sv-ai-body'; while (article.firstChild) body.append(article.firstChild);
      details.append(summary, body); article.append(details); shield(summary);
    });
  }

  if (path === imageDashboard) setupImageDashboard();
  if (path === catalogAdmin) setupCatalog();
  if (path === imageValidate) setupValidation();
})();

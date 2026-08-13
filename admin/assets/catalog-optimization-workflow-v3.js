(() => {
  'use strict';

  if (window.location.pathname !== '/admin/catalog-optimization/admin_catalog.php') return;

  const $ = (selector, root = document) => root.querySelector(selector);
  const $$ = (selector, root = document) => [...root.querySelectorAll(selector)];
  const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

  const style = document.createElement('style');
  style.id = 'sv-catalog-workflow-v3-style';
  style.textContent = `
    :root{--sv-bg:#f6f8fb;--sv-card:#fff;--sv-line:#e2e8f0;--sv-text:#0f172a;--sv-muted:#64748b;--sv-primary:#1769aa;--sv-primary-dark:#0f4f82;--sv-green:#16803b;--sv-green-bg:#ecfdf3;--sv-amber:#9a6700;--sv-amber-bg:#fff8e1;--sv-red:#b42318;--sv-red-bg:#fff1f1;--sv-radius:14px}
    body{background:var(--sv-bg)!important}
    main.wrap{max-width:1320px!important;margin:18px auto 110px!important;color:var(--sv-text)!important}
    .top{margin:8px 0 4px!important}.top h1{font-size:30px!important;letter-spacing:-.02em}.top .badge{background:#eef6ff!important;color:var(--sv-primary-dark)!important}
    .sv-workflow-subtitle{margin:0 0 18px;color:var(--sv-muted);font-size:15px;max-width:860px;line-height:1.5}
    .sv-steps{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:10px;margin:16px 0 18px}
    .sv-step{display:flex;gap:10px;align-items:center;background:var(--sv-card);border:1px solid var(--sv-line);border-radius:12px;padding:12px 14px;color:var(--sv-muted);font-weight:750}
    .sv-step b{display:grid;place-items:center;width:28px;height:28px;border-radius:999px;background:#eef2f7;color:#475569;flex:0 0 auto}.sv-step.is-active{border-color:#9dc4e6;color:var(--sv-primary-dark);box-shadow:0 6px 22px rgba(15,23,42,.05)}.sv-step.is-active b{background:var(--sv-primary);color:#fff}
    .sv-main-panel{background:var(--sv-card)!important;border:1px solid var(--sv-line)!important;border-radius:var(--sv-radius)!important;padding:0!important;overflow:hidden;box-shadow:0 10px 30px rgba(15,23,42,.05)}
    .sv-main-head{padding:18px 20px 12px;border-bottom:1px solid var(--sv-line)}.sv-main-head h2{margin:0 0 5px;font-size:20px}.sv-main-head p{margin:0;color:var(--sv-muted);font-size:14px}
    .sv-config{display:grid;grid-template-columns:minmax(170px,.8fr) minmax(190px,1fr) minmax(160px,.7fr);gap:12px;padding:16px 20px;border-bottom:1px solid var(--sv-line);background:#fbfcfe}
    .sv-field{display:grid;gap:6px}.sv-field>span{font-size:12px;font-weight:850;color:#475569;text-transform:uppercase;letter-spacing:.04em}.sv-field select,.sv-field input{width:100%;min-height:44px;padding:10px 12px;border:1px solid #cbd5e1;border-radius:9px;background:#fff;font:inherit;color:var(--sv-text)}
    .sv-channel-tip{grid-column:1/-1;font-size:13px;color:#475569;background:#fff;border:1px solid var(--sv-line);border-radius:9px;padding:10px 12px;line-height:1.45}
    .sv-candidate-tools{display:flex;align-items:center;gap:9px;flex-wrap:wrap;padding:14px 20px 10px}.sv-search{flex:1 1 260px;min-width:190px;position:relative}.sv-search input{width:100%;min-height:42px;padding:10px 12px 10px 38px;border:1px solid #cbd5e1;border-radius:9px;font:inherit;background:#fff}.sv-search span{position:absolute;left:12px;top:10px;color:#94a3b8}
    .sv-chip-btn,.sv-secondary,.sv-primary-btn{border:0;border-radius:9px;padding:10px 13px;font-weight:850;cursor:pointer;font:inherit}.sv-chip-btn{background:#eef2f7;color:#334155}.sv-chip-btn.is-active{background:#dceeff;color:#075985}.sv-secondary{background:#eef2f7;color:#334155}.sv-primary-btn{background:var(--sv-primary);color:#fff}.sv-primary-btn:hover{background:var(--sv-primary-dark)}.sv-primary-btn:disabled,.sv-secondary:disabled{opacity:.5;cursor:not-allowed}
    .sv-summary{display:flex;gap:8px;align-items:center;flex-wrap:wrap;padding:0 20px 12px}.sv-summary span{font-size:12px;font-weight:800;color:#475569;background:#f8fafc;border:1px solid var(--sv-line);border-radius:999px;padding:5px 9px}.sv-summary strong{color:var(--sv-text)}
    .sv-candidates{display:grid;gap:8px;max-height:520px;overflow:auto;padding:0 20px 18px}.sv-candidate{display:grid;grid-template-columns:auto minmax(0,1fr) auto;gap:11px;align-items:center;padding:12px 13px;background:#fff;border:1px solid var(--sv-line);border-radius:11px;cursor:pointer}.sv-candidate:hover{border-color:#9dc4e6}.sv-candidate.is-selected{border-color:var(--sv-primary);box-shadow:0 0 0 2px rgba(23,105,170,.08)}.sv-candidate input{width:19px;height:19px;accent-color:var(--sv-primary)}.sv-candidate-main{min-width:0;display:grid;gap:3px}.sv-candidate-name{font-weight:850;overflow-wrap:anywhere}.sv-candidate-meta{font-size:12px;color:var(--sv-muted);overflow-wrap:anywhere}.sv-readiness{display:flex;gap:5px;flex-wrap:wrap;justify-content:flex-end}.sv-mini{font-size:11px;font-weight:800;padding:4px 7px;border-radius:999px;background:#eef2f7;color:#475569}.sv-mini.ok{background:#dcfce7;color:#166534}.sv-mini.miss{background:#fff1f1;color:#991b1b}
    .sv-actionbar{position:sticky;bottom:8px;z-index:25;margin:0 14px 14px;padding:12px 14px;border:1px solid #bfd7ec;border-radius:12px;background:rgba(255,255,255,.96);backdrop-filter:blur(8px);display:flex;align-items:center;justify-content:space-between;gap:12px;box-shadow:0 14px 34px rgba(15,23,42,.14)}.sv-actionbar strong{display:block}.sv-actionbar small{color:var(--sv-muted)}.sv-action-buttons{display:flex;gap:8px;flex-wrap:wrap;justify-content:flex-end}
    .sv-progress{display:none;margin:0 20px 18px;border:1px solid var(--sv-line);border-radius:11px;overflow:hidden}.sv-progress.is-visible{display:block}.sv-progress-top{padding:11px 12px;background:#f8fafc;border-bottom:1px solid var(--sv-line);display:flex;justify-content:space-between;gap:10px;font-size:13px;font-weight:800}.sv-progress-list{max-height:260px;overflow:auto}.sv-progress-row{display:grid;grid-template-columns:minmax(0,1fr) auto;gap:10px;padding:9px 12px;border-bottom:1px solid #edf2f7;font-size:13px}.sv-progress-row:last-child{border:0}.sv-progress-row .ok{color:var(--sv-green);font-weight:850}.sv-progress-row .bad{color:var(--sv-red);font-weight:850}.sv-progress-row .wait{color:var(--sv-muted);font-weight:800}
    .sv-review-heading{display:flex;align-items:flex-end;justify-content:space-between;gap:12px;flex-wrap:wrap;margin:28px 0 10px}.sv-review-heading h2{margin:0;font-size:23px}.sv-review-heading p{margin:4px 0 0;color:var(--sv-muted)}
    .sv-review-filters{display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin:0 0 12px}.sv-review-filters .sv-search{max-width:390px}.sv-review-count{font-size:13px;color:var(--sv-muted);font-weight:750;margin-left:auto}
    article.item.sv-review-card{padding:0!important;border-radius:var(--sv-radius)!important;overflow:hidden;border:1px solid var(--sv-line)!important;box-shadow:0 8px 26px rgba(15,23,42,.04);background:#fff!important;margin:12px 0!important}.sv-review-card.is-ready{border-left:5px solid var(--sv-green)!important}.sv-review-card.needs-review{border-left:5px solid #d99b00!important}.sv-review-card.has-failure{border-left:5px solid var(--sv-red)!important}
    .sv-review-card>.sv-review-summary{display:grid;grid-template-columns:auto minmax(0,1fr) auto;gap:12px;align-items:center;padding:14px 16px;background:#fff}.sv-review-summary input[type=checkbox]{width:19px;height:19px;accent-color:var(--sv-primary)}.sv-review-title{display:grid;gap:3px;min-width:0}.sv-review-title strong{font-size:15px;overflow-wrap:anywhere}.sv-review-title span{font-size:12px;color:var(--sv-muted)}.sv-result-state{font-size:12px;font-weight:900;border-radius:999px;padding:6px 9px;white-space:nowrap}.sv-result-state.ready{background:var(--sv-green-bg);color:var(--sv-green)}.sv-result-state.review{background:var(--sv-amber-bg);color:var(--sv-amber)}.sv-result-state.fail{background:var(--sv-red-bg);color:var(--sv-red)}
    .sv-review-body{border-top:1px solid var(--sv-line);padding:15px 16px 18px}.sv-review-card:not(.is-open)>.sv-review-body{display:none}.sv-review-toggle{border:0;background:#eef2f7;color:#334155;font-weight:850;border-radius:8px;padding:8px 10px;cursor:pointer;margin-left:8px}.sv-review-summary-actions{display:flex;align-items:center;gap:6px;justify-content:flex-end;flex-wrap:wrap}
    .sv-review-card .source-title{display:none!important}.sv-review-card .item-select{display:none!important}.sv-review-card .profile,.sv-review-card .endpoints{display:none}.sv-review-card .compare{grid-template-columns:1fr!important;gap:12px!important}.sv-review-card .compare>.sv-original-details{border:1px solid var(--sv-line);border-radius:10px;background:#f8fafc;overflow:hidden}.sv-original-details>summary{padding:11px 12px;cursor:pointer;font-weight:850;color:#475569}.sv-original-details>.sv-original-inner{padding:0 12px 12px}.sv-review-card .edit{border:1px solid var(--sv-line);border-radius:10px;padding:13px;background:#fff}.sv-review-card .edit h3{margin-top:0}.sv-review-card .edit label{margin:10px 0}.sv-review-card .edit input,.sv-review-card .edit textarea,.sv-review-card .regen textarea,.sv-review-card .regen select{border-radius:9px!important;border-color:#cbd5e1!important}.sv-review-card .actions{margin-top:10px}.sv-review-card .save{background:#475569!important}.sv-review-card .publish{background:var(--sv-green)!important}.sv-review-card .reject{background:#fff!important;color:var(--sv-red)!important;border:1px solid #fecaca!important}.sv-review-card .regen{margin-top:12px;border:1px solid var(--sv-line);border-radius:10px;padding:0;background:#fbfcfe}.sv-review-card .regen>h3{display:none}.sv-review-card .regen>.sv-regen-summary{display:block;padding:11px 12px;cursor:pointer;font-weight:850}.sv-review-card .regen>.sv-regen-content{padding:0 12px 12px}.sv-review-card .confirm{display:none!important}
    .sv-quality-friendly{margin:0 0 12px;padding:11px 12px;border-radius:10px;background:var(--sv-green-bg);color:#166534;border:1px solid #bbf7d0}.sv-quality-friendly.review{background:var(--sv-amber-bg);color:#6b5300;border-color:#f4dda0}.sv-quality-friendly strong{display:block;margin-bottom:4px}.sv-quality-friendly ul{margin:5px 0 0;padding-left:18px}.sv-review-card .quality{margin:0 0 12px!important}.sv-review-card .quality .checks .okc{display:none!important}.sv-review-card .quality .score{font-size:15px!important}.sv-review-card .quality>.alert{display:none!important}
    .sv-tech-details{margin-top:12px;border-top:1px dashed #cbd5e1;padding-top:10px}.sv-tech-details>summary{cursor:pointer;color:var(--sv-muted);font-size:12px;font-weight:850}.sv-tech-details .profile,.sv-tech-details .endpoints{display:block!important}
    .sv-diagnostics{margin:14px 0 18px;border:1px solid var(--sv-line);border-radius:11px;background:#fff;overflow:hidden}.sv-diagnostics>summary{cursor:pointer;padding:12px 14px;font-weight:850;color:#475569}.sv-diagnostics-body{padding:0 14px 14px}.sv-diagnostics .metrics{grid-template-columns:repeat(auto-fit,minmax(150px,1fr))!important}.sv-diagnostics .metric{min-height:70px!important}.sv-diagnostics .metric strong{font-size:21px!important}.sv-diagnostics .panel{box-shadow:none!important}.sv-diagnostics .draft-note{margin:8px 0!important}
    .sv-bulk-original{display:none!important}.sv-bulk-bar{position:fixed;left:50%;transform:translateX(-50%);bottom:14px;width:min(980px,calc(100% - 24px));z-index:80;display:none;align-items:center;justify-content:space-between;gap:12px;padding:11px 14px;border:1px solid #bfd7ec;background:rgba(255,255,255,.97);backdrop-filter:blur(10px);border-radius:13px;box-shadow:0 16px 38px rgba(15,23,42,.18)}.sv-bulk-bar.is-visible{display:flex}.sv-bulk-bar strong{display:block}.sv-bulk-bar small{color:var(--sv-muted)}.sv-bulk-actions{display:flex;gap:8px;flex-wrap:wrap}.sv-bulk-actions .apply{background:var(--sv-green);color:#fff;border:0;border-radius:9px;padding:10px 13px;font-weight:900;cursor:pointer}.sv-bulk-actions .reject{background:#fff;color:var(--sv-red);border:1px solid #fecaca;border-radius:9px;padding:10px 13px;font-weight:900;cursor:pointer}.sv-bulk-actions .clear{background:#eef2f7;color:#334155;border:0;border-radius:9px;padding:10px 13px;font-weight:850;cursor:pointer}
    .sv-empty{padding:22px;text-align:center;color:var(--sv-muted);border:1px dashed #cbd5e1;border-radius:10px;background:#f8fafc}.sv-hidden{display:none!important}
    @media(max-width:820px){.sv-steps{grid-template-columns:1fr}.sv-config{grid-template-columns:1fr}.sv-channel-tip{grid-column:auto}.sv-candidate{grid-template-columns:auto minmax(0,1fr)}.sv-readiness{grid-column:2;justify-content:flex-start}.sv-actionbar{align-items:flex-start;flex-direction:column}.sv-action-buttons{width:100%}.sv-action-buttons button{flex:1}.sv-review-card>.sv-review-summary{grid-template-columns:auto minmax(0,1fr)}.sv-review-summary-actions{grid-column:1/-1;justify-content:flex-start}.sv-review-count{width:100%;margin-left:0}.sv-bulk-bar{align-items:flex-start;flex-direction:column}.sv-bulk-actions{width:100%}.sv-bulk-actions button{flex:1}}
  `;
  document.head.appendChild(style);

  const channelTips = {
    ml: 'Mercado Livre: prioriza identidade, atributos reais e título objetivo. Preço, estoque, desconto e frete continuam protegidos.',
    shopee: 'Shopee: leitura mobile, título pesquisável e descrição escaneável, sem urgência ou promessas não comprovadas.',
    amazon: 'Amazon: título limpo, cinco bullets factuais e search terms sem repetição artificial.',
    tiktok: 'TikTok Shop: identificação clara, selling points factuais e hooks sem urgência, medo ou escassez.',
    site: 'Site próprio: SEO/GEO factual, descrição profunda e metas editáveis sem keyword stuffing.',
    erp: 'Olist / Tiny: cadastro técnico, sem copy promocional. Preço e estoque não fazem parte desta rotina.'
  };

  const qualityLabels = {
    title_length: 'Ajuste o tamanho do título para a faixa recomendada do canal.',
    title_max: 'O título ultrapassa o limite permitido para o canal.',
    title_min: 'O título está curto demais para a política do canal.',
    title_identity_first: 'Comece o título pela identidade real do produto, sem chamada promocional.',
    title_hype_prefix: 'Remova chamada promocional ou exagero do início do título.',
    title_repetition: 'Reduza palavras repetidas no título.',
    description_length: 'A descrição precisa de mais conteúdo factual ou concisão, conforme o canal.',
    bullets_count: 'Ajuste a quantidade de bullets/selling points exigida pelo canal.',
    factual_identity: 'Revise identidade, marca/modelo ou atributos para manter apenas fatos da origem.',
    protected_commerce_fields: 'Remova preço, estoque, desconto, cupom, parcelamento ou frete do conteúdo editorial.',
    protected-commerce-field: 'Remova preço, estoque ou outra condição comercial protegida.',
    no_commercial_terms: 'Remova condições comerciais do conteúdo editorial.',
    meta_title_length: 'Ajuste o meta title para a faixa de qualidade.',
    meta_description_length: 'Ajuste a meta description para a faixa de qualidade.',
    seo_keywords: 'Revise os termos de busca para evitar repetição ou termos sem evidência.',
    marketing_hooks: 'Revise os hooks para manter apenas afirmações factuais.'
  };

  function humanizeCheck(raw) {
    const key = String(raw || '').replace(/^[✓✕]\s*/, '').trim();
    if (qualityLabels[key]) return qualityLabels[key];
    const normalized = key.replace(/[_-]+/g, ' ').replace(/\s+/g, ' ').trim();
    return normalized ? `Revise: ${normalized}.` : 'Revise este requisito antes de aplicar.';
  }

  async function fetchJson(url, options = {}) {
    const response = await fetch(url, { credentials: 'same-origin', ...options });
    if (response.redirected || /\/auth\/login\.php/i.test(response.url || '')) {
      throw new Error('Sua sessão administrativa expirou. Entre novamente para continuar.');
    }
    const text = await response.text();
    let data;
    try { data = JSON.parse(text); } catch (_) { throw new Error(`O servidor respondeu em formato inválido (HTTP ${response.status}).`); }
    if (!response.ok || data?.success === false) throw new Error(data?.error || `Falha HTTP ${response.status}.`);
    return data;
  }

  function mainGenerationPanel() {
    const run = $('#run');
    return run?.closest('section.panel') || null;
  }

  function setupPageHeader() {
    const title = $('.top h1');
    if (title) title.textContent = 'Otimizar cadastros';
    const top = $('.top');
    if (!top) return;
    const old = $('.sv-workflow-subtitle');
    if (!old) {
      const subtitle = document.createElement('p');
      subtitle.className = 'sv-workflow-subtitle';
      subtitle.textContent = 'Escolha os produtos, gere melhorias específicas por canal, revise o antes e depois e só então aplique. Nada é publicado automaticamente.';
      top.insertAdjacentElement('afterend', subtitle);
      const steps = document.createElement('div');
      steps.className = 'sv-steps';
      steps.innerHTML = '<div class="sv-step is-active"><b>1</b><span>Escolher produtos</span></div><div class="sv-step"><b>2</b><span>Otimizar com IA</span></div><div class="sv-step"><b>3</b><span>Revisar e aplicar</span></div>';
      subtitle.insertAdjacentElement('afterend', steps);
    }
  }

  function groupDiagnostics(panel) {
    if (!panel || $('#sv-diagnostics')) return;
    const details = document.createElement('details');
    details.className = 'sv-diagnostics';
    details.id = 'sv-diagnostics';
    details.innerHTML = '<summary>Diagnóstico avançado e informações técnicas</summary><div class="sv-diagnostics-body"></div>';
    const body = $('.sv-diagnostics-body', details);
    const movable = [];
    const draft = $('.draft-note');
    const metrics = $('.metrics');
    const health = $('#cat-health-btn')?.closest('section.panel');
    const audit = $$('details.panel').find((el) => /eventos de IA/i.test(el.textContent || ''));
    const providerWarn = $$('.alert.warn').find((el) => /Estado dos provedores/i.test(el.textContent || ''));
    [providerWarn, draft, metrics, health, audit].forEach((el) => { if (el && el !== panel) movable.push(el); });
    movable.forEach((el) => body.appendChild(el));
    panel.insertAdjacentElement('beforebegin', details);
  }

  function buildGenerationWorkflow(panel) {
    if (!panel || panel.dataset.svV3 === '1') return;
    panel.dataset.svV3 = '1';
    panel.classList.add('sv-main-panel');

    const legacyProvider = $('#provider');
    const legacyChannel = $('#channel');
    const legacyLimit = $('#load-limit');
    const providerOptions = legacyProvider ? legacyProvider.innerHTML : '<option value="openrouter">OpenRouter</option><option value="groq">Groq</option>';
    const channelOptions = legacyChannel ? legacyChannel.innerHTML : '<option value="ml">Mercado Livre</option>';
    const initialProvider = legacyProvider?.value || 'openrouter';
    const initialChannel = legacyChannel?.value || 'ml';
    const initialLimit = legacyLimit?.value || '0';

    panel.innerHTML = `
      <div class="sv-main-head"><h2>1. Escolha o que otimizar</h2><p>Selecione o canal, a IA e os produtos. Use a busca para localizar nome, SKU ou ID.</p></div>
      <div class="sv-config">
        <label class="sv-field"><span>Canal de destino</span><select id="channel">${channelOptions}</select></label>
        <label class="sv-field"><span>IA preferida</span><select id="provider">${providerOptions}</select></label>
        <label class="sv-field"><span>Quantidade para carregar</span><input id="load-limit" type="number" min="0" max="5000" value="${String(initialLimit).replace(/[^0-9]/g, '') || '0'}"><small style="color:#64748b">0 = todos os elegíveis</small></label>
        <div id="sv-channel-tip" class="sv-channel-tip"></div>
      </div>
      <div class="sv-candidate-tools">
        <label class="sv-search"><span>⌕</span><input id="sv-candidate-search" type="search" placeholder="Buscar produto, SKU ou ID" autocomplete="off"></label>
        <button type="button" class="sv-chip-btn is-active" data-sv-candidate-filter="all">Todos</button>
        <button type="button" class="sv-chip-btn" data-sv-candidate-filter="ready">Com cadastro base</button>
        <button type="button" class="sv-chip-btn" data-sv-candidate-filter="incomplete">Incompletos</button>
        <button type="button" id="load-candidates" class="sv-secondary">Atualizar lista</button>
      </div>
      <div id="candidate-summary" class="sv-summary"><span>Carregando produtos elegíveis...</span></div>
      <div id="candidate-list" class="sv-candidates" aria-live="polite"></div>
      <div id="sv-progress" class="sv-progress" aria-live="polite"><div class="sv-progress-top"><span id="sv-progress-title">Otimizando...</span><span id="sv-progress-count"></span></div><div id="sv-progress-list" class="sv-progress-list"></div></div>
      <div class="sv-actionbar">
        <div><strong id="sv-selected-label">Nenhum produto selecionado</strong><small>A otimização cria rascunhos para revisão. Não publica.</small></div>
        <div class="sv-action-buttons"><button type="button" id="select-candidates" class="sv-secondary">Selecionar exibidos</button><button type="button" id="clear-candidates" class="sv-secondary">Limpar</button><button type="button" id="retry" class="sv-secondary">Reprocessar falhas</button><button type="button" id="run" class="sv-primary-btn" disabled>Otimizar selecionados</button></div>
      </div>`;

    $('#provider').value = initialProvider;
    $('#channel').value = initialChannel;

    const state = { items: [], query: '', filter: 'all', busy: false };
    const candidateList = $('#candidate-list');
    const summary = $('#candidate-summary');
    const selectedLabel = $('#sv-selected-label');
    const run = $('#run');

    const itemReady = (item) => Number(item.has_image) === 1 && Number(item.has_description) === 1 && Number(item.has_sku) === 1;
    const selectedIds = () => $$('.sv-candidate input[type="checkbox"]:checked', candidateList).map((input) => Number(input.value)).filter((id) => id > 0);

    function updateChannelTip() {
      const channel = $('#channel').value;
      $('#sv-channel-tip').textContent = channelTips[channel] || 'A política editorial e o quality gate serão aplicados especificamente para este canal.';
    }

    function updateSelection() {
      const count = selectedIds().length;
      selectedLabel.textContent = count ? `${count} produto${count === 1 ? '' : 's'} selecionado${count === 1 ? '' : 's'}` : 'Nenhum produto selecionado';
      run.disabled = state.busy || count === 0;
      run.textContent = count ? `Otimizar selecionados (${count})` : 'Otimizar selecionados';
    }

    function filteredItems() {
      const q = state.query.toLocaleLowerCase('pt-BR').trim();
      return state.items.filter((item) => {
        const ready = itemReady(item);
        if (state.filter === 'ready' && !ready) return false;
        if (state.filter === 'incomplete' && ready) return false;
        if (!q) return true;
        return `${item.id} ${item.name || ''} ${item.sku || ''}`.toLocaleLowerCase('pt-BR').includes(q);
      });
    }

    function renderSummary() {
      const total = state.items.length;
      const ready = state.items.filter(itemReady).length;
      const incomplete = total - ready;
      const visible = filteredItems().length;
      summary.innerHTML = `<span><strong>${total}</strong> elegíveis</span><span><strong>${ready}</strong> com base completa</span><span><strong>${incomplete}</strong> incompletos</span><span><strong>${visible}</strong> exibidos</span>`;
    }

    function readinessChip(ok, text) {
      const span = document.createElement('span');
      span.className = `sv-mini ${ok ? 'ok' : 'miss'}`;
      span.textContent = `${ok ? '✓' : '–'} ${text}`;
      return span;
    }

    function renderCandidates() {
      const currentSelected = new Set(selectedIds());
      const items = filteredItems();
      candidateList.innerHTML = '';
      if (!items.length) {
        candidateList.innerHTML = '<div class="sv-empty">Nenhum produto corresponde ao filtro atual.</div>';
        renderSummary();
        updateSelection();
        return;
      }
      items.forEach((item) => {
        const label = document.createElement('label');
        label.className = 'sv-candidate';
        const checkbox = document.createElement('input');
        checkbox.type = 'checkbox';
        checkbox.value = String(item.id);
        checkbox.dataset.candidateId = String(item.id);
        checkbox.checked = currentSelected.has(Number(item.id));
        const main = document.createElement('span');
        main.className = 'sv-candidate-main';
        const name = document.createElement('span');
        name.className = 'sv-candidate-name';
        name.textContent = `#${item.id} · ${item.name || 'Produto sem nome'}`;
        const meta = document.createElement('span');
        meta.className = 'sv-candidate-meta';
        meta.textContent = `SKU ${item.sku || 'não informado'} · prioridade ${item.priority_score ?? 0}`;
        main.append(name, meta);
        const flags = document.createElement('span');
        flags.className = 'sv-readiness';
        flags.append(
          readinessChip(Number(item.has_image) === 1, 'foto'),
          readinessChip(Number(item.has_description) === 1, 'descrição'),
          readinessChip(Number(item.has_sku) === 1, 'SKU')
        );
        label.append(checkbox, main, flags);
        label.classList.toggle('is-selected', checkbox.checked);
        checkbox.addEventListener('change', () => { label.classList.toggle('is-selected', checkbox.checked); updateSelection(); });
        candidateList.appendChild(label);
      });
      renderSummary();
      updateSelection();
    }

    async function loadCandidates() {
      if (state.busy) return;
      const channel = $('#channel').value;
      const limit = Math.max(0, Math.min(5000, Number($('#load-limit').value || 0)));
      candidateList.innerHTML = '<div class="sv-empty">Carregando produtos elegíveis...</div>';
      summary.innerHTML = '<span>Atualizando lista...</span>';
      try {
        const data = await fetchJson(`/admin/catalog-optimization/api/pending_candidates_unique.php?${new URLSearchParams({ channel, limit: String(limit) })}`);
        state.items = Array.isArray(data.items) ? data.items : [];
        renderCandidates();
      } catch (error) {
        state.items = [];
        candidateList.innerHTML = `<div class="sv-empty">${escapeHtml(error.message)}</div>`;
        summary.innerHTML = '<span>Não foi possível carregar os produtos</span>';
        updateSelection();
      }
    }

    function escapeHtml(value) {
      return String(value).replace(/[&<>"']/g, (char) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' }[char]));
    }

    function progressStart(ids) {
      const box = $('#sv-progress');
      box.classList.add('is-visible');
      $('#sv-progress-title').textContent = '2. Otimizando com IA';
      $('#sv-progress-count').textContent = `0/${ids.length}`;
      const list = $('#sv-progress-list');
      list.innerHTML = '';
      ids.forEach((id) => {
        const row = document.createElement('div');
        row.className = 'sv-progress-row';
        row.dataset.productId = String(id);
        row.innerHTML = `<span>Produto #${id}</span><span class="wait">Aguardando</span>`;
        list.appendChild(row);
      });
      box.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    }

    function progressUpdate(id, status, detail) {
      const row = $(`.sv-progress-row[data-product-id="${id}"]`);
      if (!row) return;
      const value = row.lastElementChild;
      value.className = status === 'ok' ? 'ok' : status === 'bad' ? 'bad' : 'wait';
      value.textContent = detail;
    }

    async function optimizeSelected() {
      const ids = selectedIds();
      if (!ids.length || state.busy) return;
      const channel = $('#channel').value;
      const provider = $('#provider').value;
      state.busy = true;
      updateSelection();
      progressStart(ids);
      let completed = 0;
      let success = 0;
      let failed = 0;
      let firstStaging = null;
      for (const id of ids) {
        progressUpdate(id, 'wait', 'Gerando...');
        try {
          const data = await fetchJson('/admin/catalog-optimization/api/optimize_catalog_resilient.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
            body: JSON.stringify({ product_id: id, target_channel: channel, provider })
          });
          success += 1;
          if (!firstStaging && data.staging_id) firstStaging = Number(data.staging_id);
          const score = Number.isFinite(Number(data.quality_score)) ? ` · qualidade ${data.quality_score}/100` : '';
          progressUpdate(id, 'ok', `${data.needs_review ? 'Gerado · revisar' : 'Pronto'}${score}`);
        } catch (error) {
          failed += 1;
          progressUpdate(id, 'bad', `Falhou · ${error.message}`);
        }
        completed += 1;
        $('#sv-progress-count').textContent = `${completed}/${ids.length}`;
        if (completed < ids.length) await sleep(500);
      }
      state.busy = false;
      updateSelection();
      $('#sv-progress-title').textContent = `Concluído: ${success} rascunho${success === 1 ? '' : 's'}, ${failed} falha${failed === 1 ? '' : 's'}`;
      if (success > 0) {
        const reviewButton = document.createElement('button');
        reviewButton.type = 'button';
        reviewButton.className = 'sv-primary-btn';
        reviewButton.textContent = `Revisar ${success} resultado${success === 1 ? '' : 's'}`;
        reviewButton.addEventListener('click', () => {
          sessionStorage.setItem('svCatalogFocusReview', firstStaging ? String(firstStaging) : '1');
          window.location.reload();
        });
        const actions = $('.sv-action-buttons');
        const old = $('#sv-review-created', actions);
        if (old) old.remove();
        reviewButton.id = 'sv-review-created';
        actions.appendChild(reviewButton);
      }
      await loadCandidates();
    }

    async function retryFailed(button) {
      if (state.busy) return;
      state.busy = true;
      button.disabled = true;
      try {
        const data = await fetchJson('?ajax=failed_items');
        const items = Array.isArray(data.items) ? data.items : [];
        if (!items.length) {
          window.alert('Não há falhas pendentes para reprocessar.');
          return;
        }
        progressStart(items.map((item) => Number(item.product_id)));
        let ok = 0;
        let failed = 0;
        let firstStaging = null;
        for (let index = 0; index < items.length; index += 1) {
          const item = items[index];
          const id = Number(item.product_id);
          progressUpdate(id, 'wait', 'Reprocessando...');
          try {
            const out = await fetchJson('/admin/catalog-optimization/api/optimize_catalog_resilient.php', {
              method: 'POST',
              headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
              body: JSON.stringify({ product_id: id, target_channel: item.channel, provider: item.provider_used })
            });
            ok += 1;
            if (!firstStaging && out.staging_id) firstStaging = Number(out.staging_id);
            progressUpdate(id, 'ok', 'Rascunho recuperado');
          } catch (error) {
            failed += 1;
            progressUpdate(id, 'bad', `Falhou · ${error.message}`);
          }
          $('#sv-progress-count').textContent = `${index + 1}/${items.length}`;
          if (index + 1 < items.length) await sleep(500);
        }
        $('#sv-progress-title').textContent = `Reprocessamento: ${ok} recuperado${ok === 1 ? '' : 's'}, ${failed} falha${failed === 1 ? '' : 's'}`;
        if (ok > 0) {
          sessionStorage.setItem('svCatalogFocusReview', firstStaging ? String(firstStaging) : '1');
          window.location.reload();
        }
      } catch (error) {
        window.alert(error.message);
      } finally {
        state.busy = false;
        button.disabled = false;
        updateSelection();
      }
    }

    $('#sv-candidate-search').addEventListener('input', (event) => { state.query = event.target.value || ''; renderCandidates(); });
    $$('[data-sv-candidate-filter]').forEach((button) => button.addEventListener('click', () => {
      state.filter = button.dataset.svCandidateFilter || 'all';
      $$('[data-sv-candidate-filter]').forEach((candidate) => candidate.classList.toggle('is-active', candidate === button));
      renderCandidates();
    }));
    $('#load-candidates').addEventListener('click', loadCandidates);
    $('#channel').addEventListener('change', () => { updateChannelTip(); loadCandidates(); });
    $('#select-candidates').addEventListener('click', () => { $$('.sv-candidate input[type="checkbox"]', candidateList).forEach((input) => { input.checked = true; input.closest('.sv-candidate')?.classList.add('is-selected'); }); updateSelection(); });
    $('#clear-candidates').addEventListener('click', () => { $$('.sv-candidate input[type="checkbox"]', candidateList).forEach((input) => { input.checked = false; input.closest('.sv-candidate')?.classList.remove('is-selected'); }); updateSelection(); });
    $('#run').addEventListener('click', optimizeSelected);
    $('#retry').addEventListener('click', (event) => retryFailed(event.currentTarget));

    updateChannelTip();
    loadCandidates();
  }

  function makeFriendlyQuality(article, gateApproved) {
    const quality = $('.quality', article);
    if (!quality) return;
    const failed = $$('.checks .badc', quality).map((node) => humanizeCheck(node.textContent));
    const box = document.createElement('div');
    box.className = `sv-quality-friendly${gateApproved && failed.length === 0 ? '' : ' review'}`;
    if (gateApproved && failed.length === 0) {
      box.innerHTML = '<strong>Pronto para aplicar</strong>O quality gate não encontrou bloqueios editoriais.';
    } else if (failed.length) {
      box.innerHTML = `<strong>${gateApproved ? 'Pode aplicar, mas vale revisar' : 'Corrija antes de aplicar'}</strong><ul>${failed.map((text) => `<li>${text}</li>`).join('')}</ul>`;
    } else {
      box.innerHTML = `<strong>${gateApproved ? 'Pronto para aplicar' : 'Precisa de revisão'}</strong>Revise os campos destacados antes de publicar.`;
    }
    quality.insertAdjacentElement('beforebegin', box);
  }

  function wrapOriginalColumn(article) {
    const compare = $('.compare', article);
    if (!compare || compare.dataset.svWrapped === '1') return;
    compare.dataset.svWrapped = '1';
    const original = compare.firstElementChild;
    if (!original || original.classList.contains('edit')) return;
    const details = document.createElement('details');
    details.className = 'sv-original-details';
    details.innerHTML = '<summary>Ver cadastro original e dados de origem</summary><div class="sv-original-inner"></div>';
    $('.sv-original-inner', details).appendChild(original);
    compare.insertBefore(details, compare.firstChild);
  }

  function wrapRegeneration(article) {
    const regen = $('.regen', article);
    if (!regen || regen.dataset.svWrapped === '1') return;
    regen.dataset.svWrapped = '1';
    const children = [...regen.children].filter((node) => !node.matches('h3'));
    const summary = document.createElement('div');
    summary.className = 'sv-regen-summary';
    summary.textContent = 'Gerar novamente com uma instrução específica';
    const content = document.createElement('div');
    content.className = 'sv-regen-content';
    children.forEach((node) => content.appendChild(node));
    regen.append(summary, content);
    content.hidden = true;
    summary.addEventListener('click', () => { content.hidden = !content.hidden; summary.textContent = content.hidden ? 'Gerar novamente com uma instrução específica' : 'Ocultar regeneração'; });
  }

  function collectTechnicalBlocks(article) {
    const blocks = [...article.children].filter((node) => node.matches?.('.profile,.endpoints'));
    if (!blocks.length) return;
    const details = document.createElement('details');
    details.className = 'sv-tech-details';
    details.innerHTML = '<summary>Detalhes técnicos do canal e mapa de publicação</summary><div class="sv-tech-body"></div>';
    const body = $('.sv-tech-body', details);
    blocks.forEach((node) => body.appendChild(node));
    article.querySelector('form')?.insertAdjacentElement('beforebegin', details);
  }

  function enhanceReviewItems() {
    const articles = $$('article.item');
    const oldHeading = $$('h2').find((node) => /Antes e depois/i.test(node.textContent || ''));
    if (oldHeading) oldHeading.classList.add('sv-hidden');
    const emptyPanel = oldHeading?.nextElementSibling?.classList.contains('panel') ? oldHeading.nextElementSibling : null;
    if (emptyPanel && /Nenhum conteudo/i.test(emptyPanel.textContent || '')) emptyPanel.classList.add('sv-hidden');

    const heading = document.createElement('div');
    heading.className = 'sv-review-heading';
    heading.id = 'sv-review';
    heading.innerHTML = '<div><h2>3. Revisar e aplicar</h2><p>Abra somente os resultados que deseja revisar. Aplicar sempre exige uma confirmação explícita.</p></div>';
    const anchor = oldHeading || articles[0] || $('.sv-main-panel');
    if (anchor) anchor.insertAdjacentElement(oldHeading ? 'beforebegin' : 'afterend', heading);

    const filters = document.createElement('div');
    filters.className = 'sv-review-filters';
    filters.innerHTML = '<label class="sv-search"><span>⌕</span><input id="sv-review-search" type="search" placeholder="Buscar resultado por produto, SKU ou canal"></label><button type="button" class="sv-chip-btn is-active" data-sv-review-filter="all">Todos</button><button type="button" class="sv-chip-btn" data-sv-review-filter="ready">Prontos</button><button type="button" class="sv-chip-btn" data-sv-review-filter="review">Precisam revisão</button><span id="sv-review-count" class="sv-review-count"></span>';
    heading.insertAdjacentElement('afterend', filters);

    articles.forEach((article) => {
      article.classList.add('sv-review-card');
      const stagingId = Number($('input[name="staging_id"]', article)?.value || 0);
      if (stagingId) article.id = `sv-review-${stagingId}`;
      const channel = $('.source-title .badge', article)?.textContent?.trim() || 'Canal';
      const sourceText = $('.source-title', article)?.textContent?.replace(/\s+/g, ' ').trim() || `Rascunho #${stagingId}`;
      const productMatch = sourceText.match(/Produto\s+#(\d+)/i);
      const skuMatch = sourceText.match(/SKU\s+([^\s]+)/i);
      const qualityStatus = $('.quality-status', article);
      const gateApproved = qualityStatus ? qualityStatus.classList.contains('good') : !$('.quality-status.bad', article);
      const hasFailure = $('.status-badge.publication_failed', article) !== null;
      article.classList.toggle('is-ready', gateApproved && !hasFailure);
      article.classList.toggle('needs-review', !gateApproved && !hasFailure);
      article.classList.toggle('has-failure', hasFailure);
      article.dataset.svState = hasFailure ? 'fail' : gateApproved ? 'ready' : 'review';
      article.dataset.svSearch = sourceText.toLocaleLowerCase('pt-BR');

      const batchCheckbox = $('input[name="selected_ids[]"]', article);
      const summary = document.createElement('div');
      summary.className = 'sv-review-summary';
      const checkbox = batchCheckbox || document.createElement('span');
      const title = document.createElement('div');
      title.className = 'sv-review-title';
      title.innerHTML = `<strong>${productMatch ? `Produto #${productMatch[1]}` : `Rascunho #${stagingId}`}</strong><span>${escapeText(channel)}${skuMatch ? ` · SKU ${escapeText(skuMatch[1])}` : ''}${stagingId ? ` · rascunho #${stagingId}` : ''}</span>`;
      const actions = document.createElement('div');
      actions.className = 'sv-review-summary-actions';
      const state = document.createElement('span');
      state.className = `sv-result-state ${hasFailure ? 'fail' : gateApproved ? 'ready' : 'review'}`;
      state.textContent = hasFailure ? 'Falha de publicação' : gateApproved ? 'Pronto para aplicar' : 'Precisa revisão';
      const toggle = document.createElement('button');
      toggle.type = 'button';
      toggle.className = 'sv-review-toggle';
      toggle.textContent = 'Revisar';
      actions.append(state, toggle);
      summary.append(checkbox, title, actions);

      const body = document.createElement('div');
      body.className = 'sv-review-body';
      const originalChildren = [...article.children].filter((node) => node !== summary);
      originalChildren.forEach((node) => body.appendChild(node));
      article.append(summary, body);
      toggle.addEventListener('click', () => {
        article.classList.toggle('is-open');
        toggle.textContent = article.classList.contains('is-open') ? 'Fechar revisão' : 'Revisar';
        if (article.classList.contains('is-open')) article.scrollIntoView({ behavior: 'smooth', block: 'start' });
      });

      makeFriendlyQuality(article, gateApproved);
      wrapOriginalColumn(article);
      wrapRegeneration(article);
      collectTechnicalBlocks(article);

      const form = $('form', article);
      const publish = $('.publish', article);
      const save = $('.save', article);
      const regenerate = $('.regenerate button[name="action"]', article);
      const reject = $('.reject', article);
      const confirmInput = $('input[name="confirm_channel"]', article);
      if (save) save.textContent = 'Salvar alterações';
      if (publish) publish.textContent = gateApproved ? `Aplicar em ${channel}` : 'Aplicação bloqueada pelo quality gate';
      if (regenerate) regenerate.textContent = 'Gerar novamente';
      if (reject) reject.textContent = 'Descartar rascunho';

      [save, regenerate, reject].filter(Boolean).forEach((button) => button.addEventListener('click', () => {
        if (stagingId) sessionStorage.setItem('svCatalogFocusReview', String(stagingId));
      }));
      if (publish && confirmInput) {
        publish.addEventListener('click', (event) => {
          if (publish.disabled) return;
          if (!confirmInput.checked) {
            const ok = window.confirm(`Aplicar este conteúdo somente em ${channel}? O quality gate será executado novamente antes da publicação.`);
            if (!ok) { event.preventDefault(); return; }
            confirmInput.checked = true;
          }
          if (stagingId) sessionStorage.setItem('svCatalogFocusReview', String(stagingId));
        });
      }
      form?.addEventListener('submit', () => { if (stagingId) sessionStorage.setItem('svCatalogFocusReview', String(stagingId)); });
    });

    setupReviewFiltering(articles);
    setupBulkBar();
    restoreReviewFocus();
  }

  function escapeText(value) {
    return String(value || '').replace(/[<>]/g, '');
  }

  function setupReviewFiltering(articles) {
    const input = $('#sv-review-search');
    let filter = 'all';
    const update = () => {
      const query = (input?.value || '').toLocaleLowerCase('pt-BR').trim();
      let visible = 0;
      articles.forEach((article) => {
        const state = article.dataset.svState || 'review';
        const matchesFilter = filter === 'all' || filter === state || (filter === 'review' && state === 'fail');
        const matchesQuery = !query || (article.dataset.svSearch || '').includes(query);
        const show = matchesFilter && matchesQuery;
        article.classList.toggle('sv-hidden', !show);
        if (show) visible += 1;
      });
      const count = $('#sv-review-count');
      if (count) count.textContent = `${visible} resultado${visible === 1 ? '' : 's'} exibido${visible === 1 ? '' : 's'}`;
    };
    input?.addEventListener('input', update);
    $$('[data-sv-review-filter]').forEach((button) => button.addEventListener('click', () => {
      filter = button.dataset.svReviewFilter || 'all';
      $$('[data-sv-review-filter]').forEach((candidate) => candidate.classList.toggle('is-active', candidate === button));
      update();
    }));
    update();
  }

  function setupBulkBar() {
    const original = $('.bulk-panel');
    const form = $('#bulk-action-form');
    if (!original || !form) return;
    original.classList.add('sv-bulk-original');
    const action = $('select[name="bulk_action"]', form);
    const confirm = $('input[name="bulk_confirm"]', form);
    const checkboxes = $$('input[name="selected_ids[]"][form="bulk-action-form"]');
    const bar = document.createElement('div');
    bar.className = 'sv-bulk-bar';
    bar.innerHTML = '<div><strong id="sv-bulk-count">0 selecionados</strong><small>Aplique vários rascunhos aprovados sem sair da revisão.</small></div><div class="sv-bulk-actions"><button type="button" class="clear">Limpar</button><button type="button" class="reject">Descartar selecionados</button><button type="button" class="apply">Aplicar selecionados</button></div>';
    document.body.appendChild(bar);

    const update = () => {
      const selected = checkboxes.filter((checkbox) => checkbox.checked);
      $('#sv-bulk-count', bar).textContent = `${selected.length} selecionado${selected.length === 1 ? '' : 's'}`;
      bar.classList.toggle('is-visible', selected.length > 0);
    };
    checkboxes.forEach((checkbox) => checkbox.addEventListener('change', update));
    $('.clear', bar).addEventListener('click', () => { checkboxes.forEach((checkbox) => { checkbox.checked = false; }); update(); });
    $('.reject', bar).addEventListener('click', () => {
      const selected = checkboxes.filter((checkbox) => checkbox.checked);
      if (!selected.length) return;
      if (!window.confirm(`Descartar ${selected.length} rascunho(s) selecionado(s) sem publicar?`)) return;
      action.value = 'bulk_reject';
      if (confirm) confirm.checked = false;
      sessionStorage.setItem('svCatalogFocusReview', '1');
      form.requestSubmit();
    });
    $('.apply', bar).addEventListener('click', () => {
      const selected = checkboxes.filter((checkbox) => checkbox.checked);
      if (!selected.length) return;
      const blocked = selected.filter((checkbox) => checkbox.closest('.sv-review-card')?.dataset.svState !== 'ready');
      if (blocked.length) {
        window.alert(`${blocked.length} item(ns) selecionado(s) ainda precisam de revisão ou estão com falha. Selecione somente itens com status “Pronto para aplicar”.`);
        return;
      }
      if (!window.confirm(`Aplicar ${selected.length} rascunho(s) aprovado(s) em seus respectivos canais? Cada item continuará sujeito ao quality gate antes da publicação.`)) return;
      action.value = 'bulk_publish';
      if (confirm) confirm.checked = true;
      sessionStorage.setItem('svCatalogFocusReview', '1');
      form.requestSubmit();
    });
    update();
  }

  function restoreReviewFocus() {
    const focus = sessionStorage.getItem('svCatalogFocusReview');
    if (!focus) return;
    sessionStorage.removeItem('svCatalogFocusReview');
    const target = focus !== '1' ? $(`#sv-review-${focus}`) : $('#sv-review');
    const fallback = $('.alert.ok,.alert.err') || $('#sv-review');
    const node = target || fallback;
    if (target?.classList.contains('sv-review-card')) {
      target.classList.add('is-open');
      const toggle = $('.sv-review-toggle', target);
      if (toggle) toggle.textContent = 'Fechar revisão';
    }
    setTimeout(() => node?.scrollIntoView({ behavior: 'smooth', block: 'start' }), 120);
  }

  function boot() {
    setupPageHeader();
    const panel = mainGenerationPanel();
    groupDiagnostics(panel);
    buildGenerationWorkflow(panel);
    enhanceReviewItems();
  }

  boot();
})();

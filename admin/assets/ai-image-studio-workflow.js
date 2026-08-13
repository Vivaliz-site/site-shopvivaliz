(() => {
  'use strict';

  const path = window.location.pathname.replace(/\/+$/, '') || '/';
  const dashboardPath = '/admin/ai-image-studio/admin_dashboard.php';
  const reviewPath = '/admin/ai-image-studio/admin_validate.php';
  if (![dashboardPath, reviewPath].includes(path)) return;

  const $ = (selector, root = document) => root.querySelector(selector);
  const $$ = (selector, root = document) => [...root.querySelectorAll(selector)];
  const wait = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

  const CHANNELS = [
    ['site', 'Site próprio'],
    ['ml', 'Mercado Livre'],
    ['shopee', 'Shopee'],
    ['amazon', 'Amazon'],
    ['tiktok', 'TikTok Shop'],
    ['erp', 'Olist / Tiny']
  ];

  const PROVIDERS = [
    ['openai', 'OpenAI — edita a foto real'],
    ['google', 'Gemini — edita a foto real'],
    ['claude', 'Claude — otimiza a cena + editor visual'],
    ['openrouter', 'OpenRouter — edita com imagem de referência'],
    ['groq', 'Groq — otimiza a cena + editor visual']
  ];

  const MODEL_DEFAULTS = {
    openai: 'gpt-image-1',
    google: 'gemini-2.5-flash-image',
    claude: '',
    openrouter: 'openai/gpt-image-1',
    groq: ''
  };

  const PROVIDER_HELP = {
    openai: 'OpenAI é solicitado primeiro como editor visual. Se estiver indisponível, a rotina pode tentar os editores de imagem configurados como fallback.',
    google: 'Gemini é solicitado primeiro como editor visual. A foto real permanece como referência obrigatória.',
    openrouter: 'OpenRouter usa a API de imagem com a foto real como referência. É necessário crédito disponível na conta.',
    claude: 'Claude atua na instrução da cena. O arquivo final ainda depende de um editor visual disponível (OpenAI, Gemini ou OpenRouter).',
    groq: 'Groq atua na instrução da cena. O arquivo final ainda depende de um editor visual disponível (OpenRouter, OpenAI ou Gemini).'
  };

  const CHANNEL_HELP = {
    site: 'Site próprio: composição limpa e consistente com a identidade real do produto.',
    ml: 'Mercado Livre: capa clara e produto dominante; hero/lifestyle nunca substituem a capa automaticamente.',
    shopee: 'Shopee: leitura forte no mobile, mantendo cor, forma, escala e atributos reais.',
    amazon: 'Amazon: capa/branco técnico é prioritário; fundo e enquadramento precisam respeitar a política do canal.',
    tiktok: 'TikTok Shop: capa clara e variações comerciais sem inventar uso, acessórios ou benefícios.',
    erp: 'Olist / Tiny: o ERP permanece fonte protegida. A publicação de imagem gerada pode continuar bloqueada quando exigiria reenviar campos comerciais.'
  };

  const IMAGE_TYPES = [
    ['cover', 'Capa do canal'],
    ['white', 'Branco técnico'],
    ['hero', 'Hero comercial'],
    ['ambient', 'Ambientada / lifestyle']
  ];

  const style = document.createElement('style');
  style.id = 'sv-image-workflow-style';
  style.textContent = `
    :root{--sv-bg:#f6f8fb;--sv-card:#fff;--sv-line:#e2e8f0;--sv-text:#0f172a;--sv-muted:#64748b;--sv-primary:#1769aa;--sv-primary-dark:#0f4f82;--sv-green:#16803b;--sv-green-bg:#ecfdf3;--sv-amber:#9a6700;--sv-amber-bg:#fff8e1;--sv-red:#b42318;--sv-red-bg:#fff1f1;--sv-radius:14px}
    body{background:var(--sv-bg)!important}
    .ais-wrap,main.wrap{max-width:1320px!important;margin:18px auto 110px!important;color:var(--sv-text)!important}
    .ais-topbar h1,.top h1{font-size:30px!important;letter-spacing:-.02em}
    .sv-image-subtitle{margin:4px 0 16px;color:var(--sv-muted);font-size:15px;max-width:900px;line-height:1.5}
    .sv-image-steps{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:10px;margin:14px 0 18px}.sv-image-step{display:flex;gap:10px;align-items:center;background:#fff;border:1px solid var(--sv-line);border-radius:12px;padding:12px 14px;color:var(--sv-muted);font-weight:800}.sv-image-step b{display:grid;place-items:center;width:28px;height:28px;border-radius:999px;background:#eef2f7;color:#475569;flex:0 0 auto}.sv-image-step.is-active{border-color:#9dc4e6;color:var(--sv-primary-dark);box-shadow:0 6px 22px rgba(15,23,42,.05)}.sv-image-step.is-active b{background:var(--sv-primary);color:#fff}
    .sv-image-panel{background:#fff;border:1px solid var(--sv-line);border-radius:var(--sv-radius);overflow:hidden;box-shadow:0 10px 30px rgba(15,23,42,.05);margin:0 0 18px}.sv-image-head{padding:18px 20px 12px;border-bottom:1px solid var(--sv-line)}.sv-image-head h2{margin:0 0 5px;font-size:20px}.sv-image-head p{margin:0;color:var(--sv-muted);font-size:14px}
    .sv-image-config{display:grid;grid-template-columns:minmax(170px,.8fr) minmax(190px,1fr) minmax(160px,.7fr);gap:12px;padding:16px 20px;background:#fbfcfe;border-bottom:1px solid var(--sv-line)}.sv-image-field{display:grid;gap:6px}.sv-image-field>span{font-size:12px;font-weight:900;color:#475569;text-transform:uppercase;letter-spacing:.04em}.sv-image-field select,.sv-image-field input{width:100%;min-height:44px;padding:10px 12px;border:1px solid #cbd5e1;border-radius:9px;background:#fff;font:inherit;color:var(--sv-text)}.sv-image-field small{color:var(--sv-muted)}.sv-image-help{grid-column:1/-1;display:grid;gap:7px}.sv-image-help div{font-size:13px;color:#475569;background:#fff;border:1px solid var(--sv-line);border-radius:9px;padding:9px 11px;line-height:1.45}
    .sv-image-types-global{display:flex;gap:8px;flex-wrap:wrap;padding:13px 20px 0}.sv-type-chip{display:flex;align-items:center;gap:6px;background:#f8fafc;border:1px solid var(--sv-line);border-radius:999px;padding:7px 10px;font-size:12px;font-weight:800}.sv-type-chip input{width:16px;height:16px;accent-color:var(--sv-primary)}
    .sv-image-tools{display:flex;align-items:center;gap:8px;flex-wrap:wrap;padding:13px 20px 10px}.sv-search{flex:1 1 280px;min-width:190px;position:relative}.sv-search input{width:100%;min-height:42px;padding:10px 12px 10px 38px;border:1px solid #cbd5e1;border-radius:9px;font:inherit;background:#fff}.sv-search span{position:absolute;left:12px;top:10px;color:#94a3b8}.sv-chip,.sv-btn,.sv-primary{border:0;border-radius:9px;padding:10px 13px;font-weight:850;cursor:pointer;font:inherit}.sv-chip,.sv-btn{background:#eef2f7;color:#334155}.sv-chip.is-active{background:#dceeff;color:#075985}.sv-primary{background:var(--sv-primary);color:#fff}.sv-primary:hover{background:var(--sv-primary-dark)}.sv-primary:disabled,.sv-btn:disabled{opacity:.5;cursor:not-allowed}
    .sv-image-summary{display:flex;gap:8px;align-items:center;flex-wrap:wrap;padding:0 20px 12px}.sv-image-summary span{font-size:12px;font-weight:800;color:#475569;background:#f8fafc;border:1px solid var(--sv-line);border-radius:999px;padding:5px 9px}.sv-image-summary strong{color:var(--sv-text)}
    .sv-image-candidates{display:grid;gap:8px;max-height:540px;overflow:auto;padding:0 20px 18px}.sv-image-candidate{background:#fff;border:1px solid var(--sv-line);border-radius:11px;overflow:hidden}.sv-image-candidate.is-selected{border-color:var(--sv-primary);box-shadow:0 0 0 2px rgba(23,105,170,.08)}.sv-image-candidate.is-unavailable{opacity:.75}.sv-image-candidate>summary{list-style:none;display:grid;grid-template-columns:auto 58px minmax(0,1fr) auto;gap:10px;align-items:center;padding:10px 12px;cursor:pointer}.sv-image-candidate>summary::-webkit-details-marker{display:none}.sv-image-candidate>summary::after{content:'▾';color:#94a3b8}.sv-image-candidate[open]>summary::after{transform:rotate(180deg)}.sv-image-check{width:19px;height:19px;accent-color:var(--sv-primary)}.sv-source-thumb{width:58px;height:58px;border:1px solid var(--sv-line);border-radius:8px;object-fit:contain;background:#f8fafc}.sv-source-placeholder{width:58px;height:58px;display:grid;place-items:center;border:1px dashed #cbd5e1;border-radius:8px;background:#f8fafc;color:#94a3b8;font-size:10px;text-align:center}.sv-image-candidate-main{min-width:0;display:grid;gap:3px}.sv-image-candidate-name{font-weight:850;overflow-wrap:anywhere}.sv-image-meta{font-size:12px;color:var(--sv-muted);overflow-wrap:anywhere}.sv-source-state{font-size:11px;font-weight:850;border-radius:999px;padding:5px 8px;white-space:nowrap;background:#dcfce7;color:#166534}.sv-source-state.miss{background:#fff1f1;color:#991b1b}.sv-image-candidate-body{border-top:1px solid var(--sv-line);padding:11px 13px;background:#fbfcfe}.sv-image-types{display:flex;gap:10px;flex-wrap:wrap}.sv-image-types label{display:flex;align-items:center;gap:5px;font-size:12px;font-weight:750}.sv-image-types input{width:16px;height:16px;accent-color:var(--sv-primary)}
    .sv-image-actionbar{position:sticky;bottom:8px;z-index:25;margin:0 14px 14px;padding:12px 14px;border:1px solid #bfd7ec;border-radius:12px;background:rgba(255,255,255,.97);backdrop-filter:blur(8px);display:flex;align-items:center;justify-content:space-between;gap:12px;box-shadow:0 14px 34px rgba(15,23,42,.14)}.sv-image-actionbar strong{display:block}.sv-image-actionbar small{color:var(--sv-muted)}.sv-image-actions{display:flex;gap:8px;flex-wrap:wrap;justify-content:flex-end}
    .sv-image-progress{display:none;margin:0 20px 18px;border:1px solid var(--sv-line);border-radius:11px;overflow:hidden}.sv-image-progress.is-visible{display:block}.sv-image-progress-top{padding:11px 12px;background:#f8fafc;border-bottom:1px solid var(--sv-line);display:flex;justify-content:space-between;gap:10px;font-size:13px;font-weight:800}.sv-image-progress-list{max-height:310px;overflow:auto}.sv-image-progress-row{display:grid;grid-template-columns:minmax(0,1fr) auto;gap:10px;padding:9px 12px;border-bottom:1px solid #edf2f7;font-size:13px}.sv-image-progress-row:last-child{border:0}.sv-image-progress-row .ok{color:var(--sv-green);font-weight:850}.sv-image-progress-row .bad{color:var(--sv-red);font-weight:850}.sv-image-progress-row .wait{color:var(--sv-muted);font-weight:800}.sv-image-progress-row .run{color:#075985;font-weight:850}
    .sv-health{margin:0 20px 15px;padding:11px 12px;border:1px solid var(--sv-line);border-radius:10px;background:#fbfcfe}.sv-health:empty{display:none}.sv-health-row{padding:7px 9px;border-left:4px solid #94a3b8;margin:5px 0;background:#fff}.sv-health-row.ok{border-left-color:var(--sv-green)}.sv-health-row.bad{border-left-color:var(--sv-red)}
    .sv-empty{padding:22px;text-align:center;color:var(--sv-muted);border:1px dashed #cbd5e1;border-radius:10px;background:#f8fafc}
    .sv-review-heading{display:flex;align-items:flex-end;justify-content:space-between;gap:12px;flex-wrap:wrap;margin:20px 0 10px}.sv-review-heading h2{margin:0;font-size:23px}.sv-review-heading p{margin:4px 0 0;color:var(--sv-muted)}.sv-review-filters{display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin:0 0 12px}.sv-review-filters .sv-search{max-width:390px}.sv-review-count{font-size:13px;color:var(--sv-muted);font-weight:800;margin-left:auto}
    article.card.sv-image-review{padding:0!important;border-radius:var(--sv-radius)!important;overflow:hidden;border:1px solid var(--sv-line)!important;box-shadow:0 8px 26px rgba(15,23,42,.04);background:#fff!important}.sv-image-review.is-ready{border-left:5px solid var(--sv-green)!important}.sv-image-review.needs-review{border-left:5px solid #d99b00!important}.sv-image-review.has-failure{border-left:5px solid var(--sv-red)!important}.sv-review-summary{display:grid;grid-template-columns:auto 70px minmax(0,1fr) auto;gap:11px;align-items:center;padding:12px 14px}.sv-review-summary input[type=checkbox]{width:19px;height:19px;accent-color:var(--sv-primary)}.sv-review-thumb{width:70px;height:70px;object-fit:contain;border:1px solid var(--sv-line);border-radius:9px;background:#f8fafc}.sv-review-title{display:grid;gap:3px;min-width:0}.sv-review-title strong{font-size:15px;overflow-wrap:anywhere}.sv-review-title span{font-size:12px;color:var(--sv-muted);overflow-wrap:anywhere}.sv-review-actions{display:flex;align-items:center;gap:6px;justify-content:flex-end;flex-wrap:wrap}.sv-result-state{font-size:12px;font-weight:900;border-radius:999px;padding:6px 9px;white-space:nowrap}.sv-result-state.ready{background:var(--sv-green-bg);color:var(--sv-green)}.sv-result-state.review{background:var(--sv-amber-bg);color:var(--sv-amber)}.sv-result-state.fail{background:var(--sv-red-bg);color:var(--sv-red)}.sv-toggle{border:0;background:#eef2f7;color:#334155;font-weight:850;border-radius:8px;padding:8px 10px;cursor:pointer}.sv-review-body{border-top:1px solid var(--sv-line);padding:14px 16px 18px}.sv-image-review:not(.is-open)>.sv-review-body{display:none}.sv-review-body>.top,.sv-review-body>.item-select{display:none!important}.sv-review-body .compare{gap:12px!important}.sv-review-body .image-box{border-radius:10px!important}.sv-review-body .profile{border-left:0!important;border-color:var(--sv-line)!important;background:#fbfcfe!important}.sv-review-body .confirm{display:none!important}.sv-review-body .publish{background:var(--sv-green)!important}.sv-review-body .reject{background:#fff!important;color:var(--sv-red)!important;border:1px solid #fecaca!important}
    .sv-review-note{padding:10px 12px;border-radius:10px;margin:0 0 12px;background:var(--sv-green-bg);border:1px solid #bbf7d0;color:#166534}.sv-review-note.review{background:var(--sv-amber-bg);border-color:#f4dda0;color:#6b5300}.sv-review-note.fail{background:var(--sv-red-bg);border-color:#fecaca;color:#991b1b}.sv-review-note strong{display:block;margin-bottom:3px}
    .sv-regen-details,.sv-tech-details{margin:12px 0;border:1px solid var(--sv-line);border-radius:10px;background:#fbfcfe;overflow:hidden}.sv-regen-details>summary,.sv-tech-details>summary{cursor:pointer;padding:11px 12px;font-weight:850;color:#475569}.sv-regen-inner,.sv-tech-inner{padding:0 12px 12px}.sv-tech-details .profile,.sv-tech-details .endpoints{display:block!important}
    .sv-image-diagnostics{margin:12px 0 18px;border:1px solid var(--sv-line);border-radius:11px;background:#fff;overflow:hidden}.sv-image-diagnostics>summary{cursor:pointer;padding:12px 14px;font-weight:850;color:#475569}.sv-image-diagnostics-body{padding:0 14px 14px}.sv-image-diagnostics-body>.alert,.sv-image-diagnostics-body>.card,.sv-image-diagnostics-body>.ais-section{box-shadow:none!important}
    .sv-bulk-original{display:none!important}.sv-image-bulk{position:fixed;left:50%;transform:translateX(-50%);bottom:14px;width:min(980px,calc(100% - 24px));z-index:80;display:none;align-items:center;justify-content:space-between;gap:12px;padding:11px 14px;border:1px solid #bfd7ec;background:rgba(255,255,255,.97);backdrop-filter:blur(10px);border-radius:13px;box-shadow:0 16px 38px rgba(15,23,42,.18)}.sv-image-bulk.is-visible{display:flex}.sv-image-bulk strong{display:block}.sv-image-bulk small{color:var(--sv-muted)}.sv-image-bulk-actions{display:flex;gap:8px;flex-wrap:wrap}.sv-image-bulk-actions button{border-radius:9px;padding:10px 13px;font-weight:900;cursor:pointer}.sv-image-bulk-actions .apply{background:var(--sv-green);color:#fff;border:0}.sv-image-bulk-actions .reject{background:#fff;color:var(--sv-red);border:1px solid #fecaca}.sv-image-bulk-actions .clear{background:#eef2f7;color:#334155;border:0}
    .sv-hidden{display:none!important}
    @media(max-width:820px){.sv-image-steps{grid-template-columns:1fr}.sv-image-config{grid-template-columns:1fr}.sv-image-help{grid-column:auto}.sv-image-candidate>summary{grid-template-columns:auto 52px minmax(0,1fr)}.sv-image-candidate>summary::after,.sv-source-state{grid-column:3}.sv-image-actionbar{align-items:flex-start;flex-direction:column}.sv-image-actions{width:100%}.sv-image-actions button,.sv-image-actions a{flex:1}.sv-review-summary{grid-template-columns:auto 62px minmax(0,1fr)}.sv-review-actions{grid-column:1/-1;justify-content:flex-start}.sv-review-count{width:100%;margin-left:0}.sv-image-bulk{align-items:flex-start;flex-direction:column}.sv-image-bulk-actions{width:100%}.sv-image-bulk-actions button{flex:1}}
  `;
  document.head.appendChild(style);

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

  function makeOptions(items) {
    return items.map(([value, label]) => `<option value="${value}">${label}</option>`).join('');
  }

  function setupSteps(root, subtitle) {
    const existing = $('.sv-image-subtitle');
    if (existing) return;
    const text = document.createElement('p');
    text.className = 'sv-image-subtitle';
    text.textContent = subtitle;
    root.insertAdjacentElement('afterend', text);
    const steps = document.createElement('div');
    steps.className = 'sv-image-steps';
    steps.innerHTML = '<div class="sv-image-step is-active"><b>1</b><span>Escolher produtos</span></div><div class="sv-image-step"><b>2</b><span>Gerar com IA</span></div><div class="sv-image-step"><b>3</b><span>Revisar e publicar</span></div>';
    text.insertAdjacentElement('afterend', steps);
  }

  function setupDashboard() {
    const topbar = $('.ais-topbar');
    if (topbar) {
      const title = $('h1', topbar);
      if (title) title.textContent = 'Gerar imagens por marketplace';
      setupSteps(topbar, 'Escolha o canal, a IA e os produtos. A foto real é sempre a referência; o resultado fica em revisão e nunca é publicado automaticamente.');
    }

    const sourceForm = $$('.ais-form form').find((form) => form.querySelector('[name="preview"], [name="run_batch"]'));
    const host = sourceForm?.closest('.ais-form') || $('.ais-form');
    if (!host) return;

    const params = new URLSearchParams(window.location.search);
    const initialChannel = params.get('target_channel') || sourceForm?.querySelector('[name="target_channel"]')?.value || sessionStorage.getItem('svImageChannel') || 'site';
    const initialProvider = params.get('provider') || sourceForm?.querySelector('[name="provider"]')?.value || sessionStorage.getItem('svImageProvider') || 'openai';
    const initialModel = params.get('model') ?? sourceForm?.querySelector('[name="model"]')?.value ?? MODEL_DEFAULTS[initialProvider] ?? '';
    const initialLimit = Number(params.get('page_size') || params.get('limit') || sourceForm?.querySelector('[name="page_size"], [name="limit"]')?.value || 100);

    host.className = 'sv-image-panel';
    host.innerHTML = `
      <div class="sv-image-head"><h2>1. Escolha o que gerar</h2><p>Produtos sem uma foto real válida ficam visíveis para diagnóstico, mas não podem entrar na fila.</p></div>
      <div class="sv-image-config">
        <label class="sv-image-field"><span>Marketplace</span><select id="sv-image-channel">${makeOptions(CHANNELS)}</select></label>
        <label class="sv-image-field"><span>IA preferida</span><select id="sv-image-provider">${makeOptions(PROVIDERS)}</select></label>
        <label class="sv-image-field"><span>Quantidade para carregar</span><input id="sv-image-limit" type="number" min="0" max="5000" value="${Math.max(0, Math.min(5000, initialLimit || 100))}"><small>0 = todos os elegíveis</small></label>
        <div class="sv-image-help"><div id="sv-channel-help"></div><div id="sv-provider-help"></div></div>
        <label class="sv-image-field" style="grid-column:1/-1"><span>Modelo (avançado / opcional)</span><input id="sv-image-model" type="text" value=""><small>Deixe o padrão sugerido, a menos que esteja testando um modelo compatível com imagem de referência.</small></label>
      </div>
      <div class="sv-image-types-global" id="sv-global-types"></div>
      <div class="sv-image-tools">
        <label class="sv-search"><span>⌕</span><input id="sv-image-search" type="search" placeholder="Buscar produto, SKU ou ID" autocomplete="off"></label>
        <button type="button" class="sv-chip is-active" data-image-filter="all">Todos</button>
        <button type="button" class="sv-chip" data-image-filter="ready">Com foto real</button>
        <button type="button" class="sv-chip" data-image-filter="missing">Sem foto válida</button>
        <button type="button" id="sv-image-load" class="sv-btn">Atualizar lista</button>
        <button type="button" id="sv-image-health" class="sv-btn">Testar IAs</button>
      </div>
      <div id="sv-image-health-result" class="sv-health"></div>
      <div id="sv-image-summary" class="sv-image-summary"><span>Carregando produtos elegíveis...</span></div>
      <div id="sv-image-candidates" class="sv-image-candidates" aria-live="polite"></div>
      <div id="sv-image-progress" class="sv-image-progress" aria-live="polite"><div class="sv-image-progress-top"><span id="sv-image-progress-title">2. Gerando com IA</span><span id="sv-image-progress-count"></span></div><div id="sv-image-progress-list" class="sv-image-progress-list"></div></div>
      <div class="sv-image-actionbar"><div><strong id="sv-image-selected">Nenhum produto selecionado</strong><small>Gerar cria rascunhos. Publicação só acontece na etapa de revisão.</small></div><div class="sv-image-actions"><button type="button" id="sv-image-select-visible" class="sv-btn">Selecionar exibidos</button><button type="button" id="sv-image-clear" class="sv-btn">Limpar</button><button type="button" id="sv-image-run" class="sv-primary" disabled>Gerar selecionados</button><a href="/admin/ai-image-studio/admin_validate.php" class="sv-btn" style="text-decoration:none">Abrir revisão</a></div></div>`;

    const state = {
      items: [],
      selected: new Set(),
      types: new Map(),
      query: '',
      filter: 'all',
      busy: false,
      requestGeneration: 0,
      jobs: new Map()
    };

    const channel = $('#sv-image-channel');
    const provider = $('#sv-image-provider');
    const model = $('#sv-image-model');
    channel.value = CHANNELS.some(([key]) => key === initialChannel) ? initialChannel : 'site';
    provider.value = PROVIDERS.some(([key]) => key === initialProvider) ? initialProvider : 'openai';
    model.value = initialModel || MODEL_DEFAULTS[provider.value] || '';

    const globalTypes = $('#sv-global-types');
    IMAGE_TYPES.forEach(([value, label]) => {
      const item = document.createElement('label');
      item.className = 'sv-type-chip';
      const checkbox = document.createElement('input');
      checkbox.type = 'checkbox'; checkbox.checked = true; checkbox.value = value; checkbox.dataset.globalImageType = value;
      item.append(checkbox, document.createTextNode(label));
      globalTypes.appendChild(item);
    });

    function defaultTypes() {
      return new Set($$('[data-global-image-type]:checked').map((input) => input.value));
    }

    function visibleItems() {
      const q = state.query.toLocaleLowerCase('pt-BR').trim();
      return state.items.filter((item) => {
        const hasImage = Boolean(item.has_image);
        if (state.filter === 'ready' && !hasImage) return false;
        if (state.filter === 'missing' && hasImage) return false;
        if (!q) return true;
        return `${item.id} ${item.name || ''} ${item.sku || ''} ${item.category || ''}`.toLocaleLowerCase('pt-BR').includes(q);
      });
    }

    function updateHelp() {
      $('#sv-channel-help').textContent = CHANNEL_HELP[channel.value] || 'A política visual específica do canal será aplicada.';
      $('#sv-provider-help').textContent = PROVIDER_HELP[provider.value] || 'A foto real será preservada como referência.';
      sessionStorage.setItem('svImageChannel', channel.value);
      sessionStorage.setItem('svImageProvider', provider.value);
    }

    function updateSelection() {
      const count = state.selected.size;
      $('#sv-image-selected').textContent = count ? `${count} produto${count === 1 ? '' : 's'} selecionado${count === 1 ? '' : 's'}` : 'Nenhum produto selecionado';
      const run = $('#sv-image-run');
      run.disabled = state.busy || count === 0;
      run.textContent = count ? `Gerar selecionados (${count})` : 'Gerar selecionados';
      ['sv-image-channel', 'sv-image-provider', 'sv-image-model', 'sv-image-limit', 'sv-image-load', 'sv-image-health'].forEach((id) => {
        const control = $(`#${id}`); if (control) control.disabled = state.busy;
      });
    }

    function updateSummary() {
      const ready = state.items.filter((item) => Boolean(item.has_image)).length;
      $('#sv-image-summary').innerHTML = `<span><strong>${state.items.length}</strong> elegíveis</span><span><strong>${ready}</strong> com foto real</span><span><strong>${state.items.length - ready}</strong> precisam corrigir a origem</span><span><strong>${visibleItems().length}</strong> exibidos</span>`;
    }

    function renderCandidates() {
      const list = $('#sv-image-candidates');
      const items = visibleItems();
      list.innerHTML = '';
      if (!items.length) {
        list.innerHTML = '<div class="sv-empty">Nenhum produto corresponde ao filtro atual.</div>';
        updateSummary(); updateSelection(); return;
      }
      items.forEach((item) => {
        const id = Number(item.id);
        if (!state.types.has(id)) state.types.set(id, defaultTypes());
        const details = document.createElement('details');
        details.className = `sv-image-candidate${item.has_image ? '' : ' is-unavailable'}`;
        details.classList.toggle('is-selected', state.selected.has(id));
        const summary = document.createElement('summary');
        const checkbox = document.createElement('input');
        checkbox.type = 'checkbox'; checkbox.className = 'sv-image-check'; checkbox.checked = state.selected.has(id); checkbox.disabled = !item.has_image;
        checkbox.setAttribute('aria-label', `Selecionar produto ${id}`);
        checkbox.addEventListener('click', (event) => event.stopPropagation());
        checkbox.addEventListener('change', () => {
          if (checkbox.checked) state.selected.add(id); else state.selected.delete(id);
          details.classList.toggle('is-selected', checkbox.checked); updateSelection();
        });
        let thumb;
        if (item.image_url) {
          thumb = document.createElement('img'); thumb.className = 'sv-source-thumb'; thumb.src = item.image_url; thumb.alt = 'Foto real cadastrada'; thumb.loading = 'lazy';
        } else {
          thumb = document.createElement('span'); thumb.className = 'sv-source-placeholder'; thumb.textContent = item.has_image ? 'fonte ERP' : 'sem foto';
        }
        const main = document.createElement('span'); main.className = 'sv-image-candidate-main';
        const name = document.createElement('span'); name.className = 'sv-image-candidate-name'; name.textContent = `#${id} · ${item.name || 'Produto sem nome'}`;
        const meta = document.createElement('span'); meta.className = 'sv-image-meta'; meta.textContent = `SKU ${item.sku || 'não informado'}${item.category ? ` · ${item.category}` : ''}`;
        main.append(name, meta);
        const sourceState = document.createElement('span'); sourceState.className = `sv-source-state${item.has_image ? '' : ' miss'}`; sourceState.textContent = item.has_image ? 'Foto real OK' : 'Corrigir origem';
        summary.append(checkbox, thumb, main, sourceState);
        const body = document.createElement('div'); body.className = 'sv-image-candidate-body';
        const types = document.createElement('div'); types.className = 'sv-image-types';
        IMAGE_TYPES.forEach(([value, label]) => {
          const typeLabel = document.createElement('label'); const input = document.createElement('input'); input.type = 'checkbox'; input.value = value; input.checked = state.types.get(id).has(value); input.disabled = !item.has_image;
          input.addEventListener('change', () => { const selectedTypes = state.types.get(id); if (input.checked) selectedTypes.add(value); else selectedTypes.delete(value); });
          typeLabel.append(input, document.createTextNode(label)); types.appendChild(typeLabel);
        });
        const hint = document.createElement('div'); hint.className = 'sv-image-meta'; hint.style.marginTop = '7px'; hint.textContent = item.has_image ? 'A geração usa a foto real do produto como referência e aplica a política do marketplace escolhido.' : 'Sincronize/corrija a imagem do SKU no ERP/site antes de tentar gerar.';
        body.append(types, hint); details.append(summary, body); list.appendChild(details);
      });
      updateSummary(); updateSelection();
    }

    async function loadCandidates() {
      if (state.busy) return;
      const generation = ++state.requestGeneration;
      state.selected.clear(); state.types.clear(); updateSelection();
      $('#sv-image-candidates').innerHTML = '<div class="sv-empty">Carregando produtos elegíveis...</div>';
      $('#sv-image-summary').innerHTML = '<span>Atualizando lista...</span>';
      try {
        const limit = Math.max(0, Math.min(5000, Number($('#sv-image-limit').value || 0)));
        const data = await fetchJson(`/admin/ai-image-studio/api/pending_candidates.php?${new URLSearchParams({ target_channel: channel.value, limit: String(limit) })}`);
        if (generation !== state.requestGeneration) return;
        state.items = Array.isArray(data.items) ? data.items : [];
        renderCandidates();
      } catch (error) {
        if (generation !== state.requestGeneration) return;
        state.items = [];
        const box = document.createElement('div'); box.className = 'sv-empty'; box.textContent = error.message;
        $('#sv-image-candidates').innerHTML = ''; $('#sv-image-candidates').appendChild(box);
        $('#sv-image-summary').innerHTML = '<span>Não foi possível carregar os produtos</span>';
      }
    }

    async function testProviders() {
      const out = $('#sv-image-health-result'); out.innerHTML = '<div class="sv-image-meta">Testando autenticação dos provedores sem gerar imagens...</div>';
      try {
        const data = await fetchJson('/admin/ai-image-studio/api/provider_health_check.php');
        out.innerHTML = '';
        Object.entries(data.providers || {}).forEach(([key, info]) => {
          const row = document.createElement('div'); row.className = `sv-health-row ${info.ok ? 'ok' : 'bad'}`;
          const label = PROVIDERS.find(([value]) => value === key)?.[1]?.split(' — ')[0] || key;
          const strong = document.createElement('strong'); strong.textContent = `${info.ok ? '✓' : '✕'} ${label}`;
          row.append(strong, document.createTextNode(` — ${info.detail || (info.ok ? 'disponível' : 'indisponível')}`)); out.appendChild(row);
        });
      } catch (error) {
        out.innerHTML = ''; const row = document.createElement('div'); row.className = 'sv-health-row bad'; row.textContent = error.message; out.appendChild(row);
      }
    }

    function progressStart(ids) {
      const box = $('#sv-image-progress'); box.classList.add('is-visible');
      $('#sv-image-progress-title').textContent = '2. Enfileirando e acompanhando a geração'; $('#sv-image-progress-count').textContent = `0/${ids.length}`;
      const list = $('#sv-image-progress-list'); list.innerHTML = '';
      ids.forEach((id) => {
        const item = state.items.find((candidate) => Number(candidate.id) === id);
        const row = document.createElement('div'); row.className = 'sv-image-progress-row'; row.dataset.productId = String(id);
        const name = document.createElement('span'); name.textContent = `#${id} · ${item?.name || 'Produto'}`;
        const status = document.createElement('span'); status.className = 'wait'; status.textContent = 'Aguardando'; row.append(name, status); list.appendChild(row);
      });
      box.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    }

    function progressUpdate(productId, kind, message) {
      const row = $(`.sv-image-progress-row[data-product-id="${CSS.escape(String(productId))}"]`); if (!row) return;
      const status = row.lastElementChild; status.className = kind; status.textContent = message;
    }

    async function pollJobs() {
      const active = [...state.jobs.entries()].filter(([, job]) => !job.terminal);
      if (!active.length) return;
      try {
        const ids = active.map(([jobId]) => jobId).join(',');
        const data = await fetchJson(`/admin/ai-image-studio/api/generation_status.php?ids=${encodeURIComponent(ids)}`);
        (data.jobs || []).forEach((job) => {
          const id = Number(job.id); const local = state.jobs.get(id); if (!local) return;
          if (job.status === 'done') { local.terminal = true; progressUpdate(local.productId, 'ok', 'Gerado · revisar'); }
          else if (job.status === 'failed') { local.terminal = true; progressUpdate(local.productId, 'bad', `Falhou · ${job.last_error || 'provedor indisponível'}`); }
          else if (job.status === 'running') progressUpdate(local.productId, 'run', 'Gerando...');
          else progressUpdate(local.productId, 'wait', 'Na fila');
        });
      } catch (_) {
        // Polling é conveniência de UI. A fila continua no servidor mesmo se uma consulta falhar.
      }
    }

    async function generateSelected() {
      const ids = [...state.selected]; if (!ids.length || state.busy) return;
      const invalidTypes = ids.filter((id) => !(state.types.get(id)?.size));
      if (invalidTypes.length) { window.alert(`Marque ao menos um tipo de imagem para ${invalidTypes.length} produto(s).`); return; }
      state.busy = true; state.jobs.clear(); updateSelection(); progressStart(ids);
      let queued = 0; let failed = 0;
      for (let index = 0; index < ids.length; index += 1) {
        const productId = ids[index]; progressUpdate(productId, 'wait', 'Enfileirando...');
        try {
          const data = await fetchJson('/admin/ai-image-studio/api/enqueue_generation.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
            body: JSON.stringify({ product_id: productId, provider: provider.value, model: model.value.trim(), target_channel: channel.value, image_types: [...state.types.get(productId)] })
          });
          queued += 1; state.jobs.set(Number(data.job_id), { productId, terminal: false }); progressUpdate(productId, 'wait', `Na fila · job #${data.job_id}`);
        } catch (error) {
          failed += 1; progressUpdate(productId, 'bad', `Falhou ao enfileirar · ${error.message}`);
        }
        $('#sv-image-progress-count').textContent = `${index + 1}/${ids.length}`;
        if (index + 1 < ids.length) await wait(350);
      }
      $('#sv-image-progress-title').textContent = `Fila criada: ${queued} produto${queued === 1 ? '' : 's'}, ${failed} falha${failed === 1 ? '' : 's'}`;
      const deadline = Date.now() + 120000;
      while ([...state.jobs.values()].some((job) => !job.terminal) && Date.now() < deadline) { await pollJobs(); await wait(2500); }
      const pending = [...state.jobs.values()].filter((job) => !job.terminal).length;
      if (pending) $('#sv-image-progress-title').textContent += ` · ${pending} ainda processando em segundo plano`;
      else if (queued) $('#sv-image-progress-title').textContent += ' · processamento concluído';
      state.busy = false; updateSelection();
      if (!pending) await loadCandidates();
    }

    $('#sv-image-search').addEventListener('input', (event) => { state.query = event.target.value || ''; renderCandidates(); });
    $$('[data-image-filter]').forEach((button) => button.addEventListener('click', () => { state.filter = button.dataset.imageFilter || 'all'; $$('[data-image-filter]').forEach((candidate) => candidate.classList.toggle('is-active', candidate === button)); renderCandidates(); }));
    channel.addEventListener('change', () => { updateHelp(); loadCandidates(); });
    provider.addEventListener('change', () => { const previousDefault = Object.values(MODEL_DEFAULTS).includes(model.value); if (previousDefault || model.value.trim() === '') model.value = MODEL_DEFAULTS[provider.value] || ''; updateHelp(); });
    $('#sv-image-load').addEventListener('click', loadCandidates);
    $('#sv-image-health').addEventListener('click', testProviders);
    $('#sv-image-select-visible').addEventListener('click', () => { visibleItems().filter((item) => item.has_image).forEach((item) => state.selected.add(Number(item.id))); renderCandidates(); });
    $('#sv-image-clear').addEventListener('click', () => { state.selected.clear(); renderCandidates(); });
    $('#sv-image-run').addEventListener('click', generateSelected);
    $$('[data-global-image-type]').forEach((input) => input.addEventListener('change', () => { const defaults = defaultTypes(); visibleItems().forEach((item) => state.types.set(Number(item.id), new Set(defaults))); renderCandidates(); }));

    updateHelp(); loadCandidates();
  }

  function setupReviewDiagnostics() {
    const main = $('main.wrap'); if (!main) return;
    const providerWarning = $$('.alert.warn', main).find((el) => /Estado dos provedores/i.test(el.textContent || ''));
    const audit = $$('details.card', main).find((el) => /Últimos eventos de IA/i.test(el.textContent || ''));
    if (!providerWarning && !audit) return;
    const details = document.createElement('details'); details.className = 'sv-image-diagnostics';
    details.innerHTML = '<summary>Diagnóstico avançado de provedores e eventos de IA</summary><div class="sv-image-diagnostics-body"></div>';
    const body = $('.sv-image-diagnostics-body', details); [providerWarning, audit].filter(Boolean).forEach((node) => body.appendChild(node));
    const note = $('.note', main); (note || $('.top', main))?.insertAdjacentElement('afterend', details);
  }

  function collapseRegeneration(article) {
    const form = $('form.edit', article); if (!form) return;
    const children = [...form.children];
    const publishHeadingIndex = children.findIndex((node) => node.tagName === 'H3' && /Publicacao real|Publicação real/i.test(node.textContent || ''));
    if (publishHeadingIndex <= 0) return;
    const movable = children.slice(0, publishHeadingIndex).filter((node) => node.tagName !== 'INPUT' || node.type !== 'hidden');
    if (!movable.length) return;
    const details = document.createElement('details'); details.className = 'sv-regen-details';
    const summary = document.createElement('summary'); summary.textContent = 'Gerar novamente com outra instrução ou IA';
    const inner = document.createElement('div'); inner.className = 'sv-regen-inner';
    movable[0].insertAdjacentElement('beforebegin', details); movable.forEach((node) => inner.appendChild(node)); details.append(summary, inner);
  }

  function collapseTechnical(article) {
    const body = $('.sv-review-body', article); if (!body) return;
    const blocks = [...body.children].filter((node) => node.matches?.('.profile,.endpoints'));
    if (!blocks.length) return;
    const details = document.createElement('details'); details.className = 'sv-tech-details';
    const summary = document.createElement('summary'); summary.textContent = 'Regras do marketplace e detalhes técnicos';
    const inner = document.createElement('div'); inner.className = 'sv-tech-inner';
    blocks[0].insertAdjacentElement('beforebegin', details); blocks.forEach((node) => inner.appendChild(node)); details.append(summary, inner);
  }

  function setupReview() {
    const top = $('.top');
    if (top) {
      const title = $('h1', top); if (title) title.textContent = 'Revisar imagens geradas';
      setupSteps(top, 'Compare a foto real com a versão gerada, corrija o que for necessário e publique somente depois de uma aprovação humana explícita.');
    }
    setupReviewDiagnostics();

    const main = $('main.wrap'); if (!main) return;
    const articles = $$('section.grid > article.card', main);
    const heading = document.createElement('div'); heading.className = 'sv-review-heading'; heading.id = 'sv-image-review'; heading.innerHTML = '<div><h2>3. Revisar e publicar</h2><p>A rotina valida o arquivo e o destino; identidade, cor, forma e composição continuam exigindo comparação visual humana.</p></div>';
    const grid = $('section.grid', main); if (grid) grid.insertAdjacentElement('beforebegin', heading);
    const filters = document.createElement('div'); filters.className = 'sv-review-filters'; filters.innerHTML = '<label class="sv-search"><span>⌕</span><input id="sv-image-review-search" type="search" placeholder="Buscar produto, SKU, tipo ou canal"></label><button type="button" class="sv-chip is-active" data-review-filter="all">Todos</button><button type="button" class="sv-chip" data-review-filter="ready">Prontos para revisar</button><button type="button" class="sv-chip" data-review-filter="review">Precisam ação</button><button type="button" class="sv-chip" data-review-filter="fail">Falha de publicação</button><span id="sv-image-review-count" class="sv-review-count"></span>';
    heading.insertAdjacentElement('afterend', filters);

    articles.forEach((article) => {
      const stagingId = Number($('input[name="staging_id"]', article)?.value || 0);
      const oldTop = $('.top', article);
      const productName = $('h2', oldTop)?.textContent?.trim() || `Imagem #${stagingId}`;
      const channel = $('.badge', oldTop)?.textContent?.trim() || 'Canal';
      const meta = $('.meta', article)?.textContent?.replace(/\s+/g, ' ').trim() || '';
      const generated = $('.compare > div:nth-child(2) img', article);
      const legacy = /SEM DESTINO/i.test(channel) || $$('.alert.warn', article).some((el) => /Publicacao bloqueada/i.test(el.textContent || ''));
      const fallback = $$('.alert.warn', article).some((el) => /Sem edicao real de IA/i.test(el.textContent || '')) || /local_reference/i.test(meta);
      const publicationFailed = /publication_failed/i.test(meta);
      const qualityWarning = $('.quality-warn', article) !== null;
      const hasError = $('.alert.err', article) !== null;
      const state = publicationFailed || hasError ? 'fail' : (legacy || fallback || qualityWarning ? 'review' : 'ready');

      article.classList.add('sv-image-review'); article.dataset.svState = state; article.dataset.svSearch = `${productName} ${channel} ${meta}`.toLocaleLowerCase('pt-BR');
      if (stagingId) article.id = `sv-image-review-${stagingId}`;
      article.classList.toggle('is-ready', state === 'ready'); article.classList.toggle('needs-review', state === 'review'); article.classList.toggle('has-failure', state === 'fail');

      const batchCheckbox = $('input[name="selected_ids[]"]', article);
      const body = document.createElement('div'); body.className = 'sv-review-body'; [...article.children].forEach((child) => body.appendChild(child)); article.appendChild(body);

      const note = document.createElement('div'); note.className = `sv-review-note ${state === 'ready' ? '' : state}`;
      const strong = document.createElement('strong');
      if (publicationFailed) strong.textContent = 'A publicação anterior falhou';
      else if (fallback) strong.textContent = 'Sem edição visual de IA';
      else if (legacy) strong.textContent = 'Marketplace não definido';
      else if (qualityWarning) strong.textContent = 'Revise a resolução antes de aprovar';
      else strong.textContent = 'Arquivo tecnicamente pronto para revisão humana';
      const text = document.createTextNode(fallback ? ' Regenere quando um editor visual estiver disponível; a foto original copiada não deve ser tratada como geração concluída.' : legacy ? ' Escolha o marketplace e regenere antes de publicar.' : publicationFailed ? ' Revise a mensagem de erro e tente novamente somente depois de corrigir a causa.' : ' Compare produto, cor, forma, proporção, acessórios e composição com a foto real antes de publicar.');
      note.append(strong, text); body.prepend(note);

      collapseRegeneration(article); collapseTechnical(article);

      const summary = document.createElement('div'); summary.className = 'sv-review-summary';
      const checkbox = batchCheckbox || document.createElement('span'); if (batchCheckbox) batchCheckbox.closest('.item-select')?.classList.add('sv-hidden');
      let thumb; if (generated) { thumb = generated.cloneNode(false); thumb.className = 'sv-review-thumb'; thumb.alt = 'Imagem gerada em revisão'; } else { thumb = document.createElement('span'); thumb.className = 'sv-source-placeholder'; thumb.textContent = 'sem prévia'; }
      const title = document.createElement('div'); title.className = 'sv-review-title'; const name = document.createElement('strong'); name.textContent = productName; const sub = document.createElement('span'); sub.textContent = `${channel} · ${meta}${stagingId ? ` · rascunho #${stagingId}` : ''}`; title.append(name, sub);
      const actions = document.createElement('div'); actions.className = 'sv-review-actions'; const badge = document.createElement('span'); badge.className = `sv-result-state ${state}`; badge.textContent = state === 'ready' ? 'Pronto para revisar' : publicationFailed ? 'Falha de publicação' : fallback ? 'Regenerar' : 'Precisa ação'; const toggle = document.createElement('button'); toggle.type = 'button'; toggle.className = 'sv-toggle'; toggle.textContent = 'Revisar'; actions.append(badge, toggle); summary.append(checkbox, thumb, title, actions); article.insertBefore(summary, body);
      toggle.addEventListener('click', () => { article.classList.toggle('is-open'); toggle.textContent = article.classList.contains('is-open') ? 'Fechar revisão' : 'Revisar'; if (article.classList.contains('is-open')) article.scrollIntoView({ behavior: 'smooth', block: 'start' }); });

      const form = $('form.edit', article); const publish = $('button.publish[name="action"]', article); const regenerate = $('button.regenerate[name="action"]', article); const reject = $('button.reject[name="action"]', article); const confirm = $('input[name="confirm_channel"]', article);
      if (regenerate) regenerate.textContent = 'Gerar novamente'; if (reject) reject.textContent = 'Descartar sem publicar';
      if (publish) {
        publish.textContent = fallback ? 'Regeneração obrigatória antes de publicar' : `Aprovar e publicar em ${channel}`;
        if (fallback) { publish.disabled = true; publish.title = 'Esta linha é apenas a foto original copiada após falha dos editores de IA.'; }
      }
      [regenerate, reject].filter(Boolean).forEach((button) => button.addEventListener('click', () => { if (stagingId) sessionStorage.setItem('svImageReviewFocus', String(stagingId)); }));
      if (publish && confirm && !publish.disabled) publish.addEventListener('click', (event) => { if (!confirm.checked) { const ok = window.confirm(`Publicar esta imagem somente em ${channel}? Você confirma que comparou visualmente com a foto real e que produto, cor, forma e composição estão corretos?`); if (!ok) { event.preventDefault(); return; } confirm.checked = true; } if (stagingId) sessionStorage.setItem('svImageReviewFocus', String(stagingId)); });
      form?.addEventListener('submit', () => { if (stagingId) sessionStorage.setItem('svImageReviewFocus', String(stagingId)); });
    });

    setupReviewFilters(articles); setupReviewBulk(); restoreReviewFocus();
  }

  function setupReviewFilters(articles) {
    const input = $('#sv-image-review-search'); let filter = 'all';
    const update = () => { const q = (input?.value || '').toLocaleLowerCase('pt-BR').trim(); let visible = 0; articles.forEach((article) => { const show = (filter === 'all' || article.dataset.svState === filter || (filter === 'review' && article.dataset.svState === 'fail')) && (!q || (article.dataset.svSearch || '').includes(q)); article.classList.toggle('sv-hidden', !show); if (show) visible += 1; }); const count = $('#sv-image-review-count'); if (count) count.textContent = `${visible} imagem${visible === 1 ? '' : 's'} exibida${visible === 1 ? '' : 's'}`; };
    input?.addEventListener('input', update); $$('[data-review-filter]').forEach((button) => button.addEventListener('click', () => { filter = button.dataset.reviewFilter || 'all'; $$('[data-review-filter]').forEach((candidate) => candidate.classList.toggle('is-active', candidate === button)); update(); })); update();
  }

  function setupReviewBulk() {
    const original = $('.bulk-panel'); const form = $('#bulk-action-form'); if (!original || !form) return; original.classList.add('sv-bulk-original');
    const action = $('select[name="bulk_action"]', form); const confirm = $('input[name="bulk_confirm"]', form); const boxes = $$('input[name="selected_ids[]"][form="bulk-action-form"]');
    const bar = document.createElement('div'); bar.className = 'sv-image-bulk'; bar.innerHTML = '<div><strong id="sv-image-bulk-count">0 selecionadas</strong><small>Publicação em lote só aceita imagens sem bloqueio operacional.</small></div><div class="sv-image-bulk-actions"><button type="button" class="clear">Limpar</button><button type="button" class="reject">Descartar selecionadas</button><button type="button" class="apply">Publicar selecionadas</button></div>'; document.body.appendChild(bar);
    const update = () => { const selected = boxes.filter((box) => box.checked); $('#sv-image-bulk-count', bar).textContent = `${selected.length} selecionada${selected.length === 1 ? '' : 's'}`; bar.classList.toggle('is-visible', selected.length > 0); };
    boxes.forEach((box) => box.addEventListener('change', update)); $('.clear', bar).addEventListener('click', () => { boxes.forEach((box) => { box.checked = false; }); update(); });
    $('.reject', bar).addEventListener('click', () => { const selected = boxes.filter((box) => box.checked); if (!selected.length || !window.confirm(`Descartar ${selected.length} imagem(ns) selecionada(s) sem publicar?`)) return; action.value = 'bulk_reject'; if (confirm) confirm.checked = false; sessionStorage.setItem('svImageReviewFocus', '1'); form.requestSubmit(); });
    $('.apply', bar).addEventListener('click', () => { const selected = boxes.filter((box) => box.checked); if (!selected.length) return; const blocked = selected.filter((box) => box.closest('.sv-image-review')?.dataset.svState !== 'ready'); if (blocked.length) { window.alert(`${blocked.length} imagem(ns) selecionada(s) ainda precisam de ação ou estão com falha. Publique em lote somente itens “Prontos para revisar” depois da conferência visual.`); return; } if (!window.confirm(`Publicar ${selected.length} imagem(ns) nos marketplaces já definidos? Confirme somente se você comparou visualmente cada imagem com a foto real.`)) return; action.value = 'bulk_publish'; if (confirm) confirm.checked = true; sessionStorage.setItem('svImageReviewFocus', '1'); form.requestSubmit(); }); update();
  }

  function restoreReviewFocus() {
    const focus = sessionStorage.getItem('svImageReviewFocus'); if (!focus) return; sessionStorage.removeItem('svImageReviewFocus');
    const target = focus !== '1' ? $(`#sv-image-review-${CSS.escape(focus)}`) : $('#sv-image-review'); const fallback = $('.alert.ok,.alert.err') || $('#sv-image-review'); const node = target || fallback;
    if (target?.classList.contains('sv-image-review')) { target.classList.add('is-open'); const toggle = $('.sv-toggle', target); if (toggle) toggle.textContent = 'Fechar revisão'; }
    setTimeout(() => node?.scrollIntoView({ behavior: 'smooth', block: 'start' }), 120);
  }

  if (path === dashboardPath) setupDashboard();
  if (path === reviewPath) setupReview();
})();

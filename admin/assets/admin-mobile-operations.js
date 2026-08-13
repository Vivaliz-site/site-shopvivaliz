(() => {
  'use strict';

  const path = window.location.pathname.replace(/\/+$/, '') || '/';
  if (path !== '/admin' && path !== '/admin/index.php') return;

  const $ = (selector, root = document) => root.querySelector(selector);
  const $$ = (selector, root = document) => [...root.querySelectorAll(selector)];

  const style = document.createElement('style');
  style.id = 'sv-admin-mobile-operations-style';
  style.textContent = `
    :root{--sv-admin-bg:#f5f7fb;--sv-admin-line:#dfe5ec;--sv-admin-text:#0f172a;--sv-admin-muted:#64748b;--sv-admin-purple:#7e22ce;--sv-admin-orange:#c2410c}
    body.sv-admin-mobile{background:var(--sv-admin-bg);padding-bottom:0}
    .sv-admin-quick-grid{display:grid!important;grid-template-columns:repeat(auto-fit,minmax(190px,1fr))!important;gap:12px!important;margin-bottom:20px!important}
    .sv-admin-shortcut{display:flex!important;align-items:center!important;gap:11px!important;min-height:82px!important;padding:14px!important;border-radius:13px!important;text-align:left!important;box-shadow:0 7px 20px rgba(15,23,42,.08);line-height:1.2!important;position:relative;overflow:hidden}
    .sv-admin-shortcut::after{content:'›';margin-left:auto;font-size:25px;line-height:1;color:rgba(255,255,255,.78)}
    .sv-admin-shortcut-icon{display:grid;place-items:center;width:39px;height:39px;flex:0 0 auto;border-radius:11px;background:rgba(255,255,255,.18);font-size:21px}
    .sv-admin-shortcut-copy{display:grid;gap:4px;min-width:0}.sv-admin-shortcut-copy strong{font-size:14px;overflow-wrap:anywhere}.sv-admin-shortcut-copy small{font-size:11px;font-weight:750;opacity:.86}
    .sv-admin-shortcut.sv-admin-ai-image{background:linear-gradient(135deg,#7e22ce,#9333ea)!important}.sv-admin-shortcut.sv-admin-ai-catalog{background:linear-gradient(135deg,#c2410c,#ea580c)!important}
    .sv-admin-card-accordion{border:0}.sv-admin-card-accordion>summary{list-style:none;cursor:pointer}.sv-admin-card-accordion>summary::-webkit-details-marker{display:none}.sv-admin-card-accordion>summary .admin-card-head{margin:0}.sv-admin-card-accordion>summary .admin-card-head::after{content:'▾';margin-left:auto;color:var(--sv-admin-muted);transition:transform .15s ease}.sv-admin-card-accordion[open]>summary .admin-card-head::after{transform:rotate(180deg)}
    .sv-admin-card-body{padding-top:10px}.sv-admin-mobile-dock{display:none}
    .sv-admin-status-compact{font-size:12px;color:var(--sv-admin-muted)}
    @media(max-width:760px){
      body.sv-admin-mobile{padding-bottom:calc(76px + env(safe-area-inset-bottom))}
      body.sv-admin-mobile>div:first-of-type{padding:6px 9px!important;font-size:11px!important;gap:6px!important}
      body.sv-admin-mobile .navbar{padding:7px 0!important;position:sticky;top:29px;z-index:9998}
      body.sv-admin-mobile .nav-inner{display:grid!important;gap:7px!important;padding:0 8px!important}.brand-link{font-size:15px!important;padding:3px 1px}
      body.sv-admin-mobile .navbar-menu{display:flex!important;flex-wrap:nowrap!important;gap:5px!important;overflow-x:auto;padding:0 0 3px;scrollbar-width:thin}.nav-menu a{flex:0 0 auto!important;padding:7px 9px!important;font-size:12px!important;min-height:36px;display:inline-flex;align-items:center}
      body.sv-admin-mobile .catalog-header{padding:13px 0!important}.catalog-header-inner{display:grid!important;gap:10px!important;padding:0 9px!important}.catalog-header .eyebrow{font-size:10px!important;margin:0 0 3px}.catalog-header h1{font-size:21px!important;line-height:1.1!important;margin:0}.catalog-header .muted{font-size:12px!important;line-height:1.35!important;margin:5px 0 0}.catalog-search{width:100%!important;display:grid!important;grid-template-columns:minmax(0,1fr) auto!important}.catalog-search input{font-size:16px!important;min-height:43px}.catalog-search button{min-height:43px!important;padding:8px 11px!important}
      body.sv-admin-mobile .admin-overview{margin-top:10px!important;padding:0 8px!important}.sv-admin-quick-grid{grid-template-columns:repeat(2,minmax(0,1fr))!important;gap:8px!important;margin-bottom:12px!important}.sv-admin-shortcut{min-height:72px!important;padding:10px!important;gap:8px!important;border-radius:11px!important;box-shadow:0 4px 13px rgba(15,23,42,.08)}.sv-admin-shortcut-icon{width:33px;height:33px;border-radius:9px;font-size:18px}.sv-admin-shortcut-copy strong{font-size:12px}.sv-admin-shortcut-copy small{font-size:10px}.sv-admin-shortcut::after{font-size:19px}
      body.sv-admin-mobile .admin-card{padding:0!important;margin:8px 0!important;border-radius:11px!important;overflow:hidden}.sv-admin-card-accordion>summary{padding:11px 12px;background:#fff}.sv-admin-card-accordion>summary .admin-card-head h2{font-size:16px!important;margin:2px 0!important}.sv-admin-card-accordion>summary .eyebrow{font-size:10px!important}.sv-admin-card-body{padding:0 12px 12px}.sv-admin-card-body .muted,.sv-admin-card-body small,.sv-admin-card-body li{font-size:12px!important}.sv-admin-card-body .admin-link-list{display:grid!important;grid-template-columns:1fr 1fr!important;gap:7px!important}.sv-admin-card-body .btn{min-height:40px!important;padding:8px!important;font-size:12px!important;text-align:center}
      body.sv-admin-mobile .catalog-tools{padding:0 8px!important}.admin-map-grid{grid-template-columns:1fr!important;gap:7px!important}.admin-map-item{padding:9px!important}
      .sv-admin-mobile-dock{position:fixed;z-index:10020;left:7px;right:7px;bottom:calc(7px + env(safe-area-inset-bottom));display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:5px;padding:7px;border:1px solid rgba(148,163,184,.5);border-radius:14px;background:rgba(15,23,42,.96);box-shadow:0 14px 36px rgba(15,23,42,.3);backdrop-filter:blur(10px)}.sv-admin-mobile-dock a{display:grid;place-items:center;gap:3px;min-height:48px;padding:5px 2px;border-radius:9px;color:#fff;text-decoration:none;font-size:10px;font-weight:850;text-align:center}.sv-admin-mobile-dock a span{font-size:18px}.sv-admin-mobile-dock a:active{background:rgba(255,255,255,.13)}
    }
    @media(max-width:390px){.sv-admin-quick-grid{grid-template-columns:1fr!important}.sv-admin-shortcut{min-height:62px!important}.sv-admin-card-body .admin-link-list{grid-template-columns:1fr!important}}
  `;
  document.head.appendChild(style);
  document.body.classList.add('sv-admin-mobile');

  function splitShortcut(anchor) {
    if (anchor.dataset.svAdminShortcut === '1') return;
    anchor.dataset.svAdminShortcut = '1';
    anchor.classList.add('sv-admin-shortcut');
    const raw = anchor.textContent.replace(/\s+/g, ' ').trim();
    const match = raw.match(/^([^\p{L}\p{N}]+)?\s*(.+)$/u);
    const iconText = (match?.[1] || '•').trim();
    const titleText = (match?.[2] || raw).trim();
    let helper = 'Abrir área administrativa';
    if (anchor.href.includes('/ai-image-studio/')) {
      anchor.classList.add('sv-admin-ai-image');
      helper = 'Selecionar, gerar e revisar imagens';
    } else if (anchor.href.includes('/catalog-optimization/')) {
      anchor.classList.add('sv-admin-ai-catalog');
      helper = 'Selecionar, otimizar e aplicar cadastro';
    } else if (anchor.href.includes('/pedidos')) helper = 'Consultar e acompanhar pedidos';
    else if (anchor.href.includes('/produtos')) helper = 'Consultar e editar produtos';
    const icon = document.createElement('span');
    icon.className = 'sv-admin-shortcut-icon';
    icon.textContent = iconText || '•';
    const copy = document.createElement('span');
    copy.className = 'sv-admin-shortcut-copy';
    const strong = document.createElement('strong');
    strong.textContent = titleText;
    const small = document.createElement('small');
    small.textContent = helper;
    copy.append(strong, small);
    anchor.textContent = '';
    anchor.append(icon, copy);
  }

  function enhanceShortcuts() {
    const overview = $('.admin-overview');
    if (!overview) return;
    const grid = overview.firstElementChild;
    if (!grid) return;
    grid.classList.add('sv-admin-quick-grid');
    $$(':scope > a', grid).forEach(splitShortcut);
  }

  function enhanceCards() {
    const media = window.matchMedia('(max-width:760px)');
    const cards = $$('.admin-overview > article.admin-card');
    cards.forEach((card, index) => {
      if (card.dataset.svAccordion === '1') return;
      const head = $('.admin-card-head', card);
      if (!head) return;
      card.dataset.svAccordion = '1';
      const details = document.createElement('details');
      details.className = 'sv-admin-card-accordion';
      const summary = document.createElement('summary');
      const body = document.createElement('div');
      body.className = 'sv-admin-card-body';
      summary.appendChild(head);
      [...card.childNodes].forEach((node) => {
        if (node !== details && node !== summary && node !== head) body.appendChild(node);
      });
      details.append(summary, body);
      card.appendChild(details);
      details.dataset.mobileDefault = index === 0 ? 'open' : 'closed';
    });

    const sync = () => {
      $$('.sv-admin-card-accordion').forEach((details) => {
        details.open = media.matches ? details.dataset.mobileDefault === 'open' : true;
      });
    };
    sync();
    if (typeof media.addEventListener === 'function') media.addEventListener('change', sync);
  }

  function addDock() {
    if ($('.sv-admin-mobile-dock')) return;
    const dock = document.createElement('nav');
    dock.className = 'sv-admin-mobile-dock';
    dock.setAttribute('aria-label', 'Atalhos administrativos no celular');
    const items = [
      ['/admin/ai-image-studio/admin_dashboard.php', '🖼️', 'Imagens'],
      ['/admin/catalog-optimization/admin_catalog.php', '📝', 'Otimizar'],
      ['/admin/produtos.php', '📦', 'Produtos'],
      ['/admin/pedidos.php', '📋', 'Pedidos']
    ];
    items.forEach(([href, icon, label]) => {
      const link = document.createElement('a');
      link.href = href;
      const iconNode = document.createElement('span');
      iconNode.textContent = icon;
      const labelNode = document.createElement('b');
      labelNode.textContent = label;
      link.append(iconNode, labelNode);
      dock.appendChild(link);
    });
    document.body.appendChild(dock);
  }

  enhanceShortcuts();
  enhanceCards();
  addDock();
})();

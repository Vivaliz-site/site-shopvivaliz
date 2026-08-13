(() => {
  'use strict';

  const page = window.location.pathname.replace(/\/+$/, '') || '/';
  if (!['/admin', '/admin/index.php'].includes(page)) return;

  const definitions = [
    {
      key: 'images',
      href: '/admin/ai-image-studio/admin_dashboard.php',
      icon: '🖼️',
      title: 'AI Image Studio',
      subtitle: 'Gerar e revisar imagens por marketplace',
      accent: 'purple',
    },
    {
      key: 'catalog',
      href: '/admin/catalog-optimization/admin_catalog.php',
      icon: '📝',
      title: 'Otimização de Cadastro',
      subtitle: 'Revisar mudanças editoriais antes de aplicar',
      accent: 'orange',
    },
  ];

  const style = document.createElement('style');
  style.id = 'sv-admin-ai-overview-style';
  style.textContent = `
    .sv-ai-shortcut-grid{align-items:stretch}
    a.sv-ai-shortcut{display:grid!important;grid-template-columns:auto minmax(0,1fr) auto;align-items:center;gap:11px;text-align:left!important;padding:13px 14px!important;min-height:92px;border:1px solid rgba(255,255,255,.22);box-shadow:0 8px 24px rgba(15,23,42,.1);overflow:hidden}
    .sv-ai-shortcut-icon{display:grid;place-items:center;width:42px;height:42px;border-radius:11px;background:rgba(255,255,255,.18);font-size:22px}
    .sv-ai-shortcut-copy{display:grid;gap:3px;min-width:0}.sv-ai-shortcut-copy strong{font-size:15px;line-height:1.15}.sv-ai-shortcut-copy small{font-size:11px;line-height:1.35;color:rgba(255,255,255,.86);font-weight:650;overflow-wrap:anywhere}.sv-ai-shortcut-copy span{font-size:12px;line-height:1.35;color:#fff;font-weight:850}
    .sv-ai-shortcut-go{font-size:18px;font-weight:900}.sv-ai-shortcut[data-health="warning"]{box-shadow:0 0 0 3px rgba(254,240,138,.42),0 8px 24px rgba(15,23,42,.12)}.sv-ai-shortcut[data-health="failure"]{box-shadow:0 0 0 3px rgba(254,202,202,.55),0 8px 24px rgba(15,23,42,.12)}
    @media(max-width:720px){a.sv-ai-shortcut{grid-template-columns:auto minmax(0,1fr);min-height:82px;padding:11px!important}.sv-ai-shortcut-go{display:none}.sv-ai-shortcut-icon{width:38px;height:38px;font-size:20px}.sv-ai-shortcut-copy strong{font-size:14px}.sv-ai-shortcut-copy small{display:none}}
  `;
  document.head.appendChild(style);

  function findCard(href) {
    return [...document.querySelectorAll('a[href]')].find((link) => {
      try { return new URL(link.href, window.location.origin).pathname === href; } catch (_) { return false; }
    }) || null;
  }

  function installCard(definition) {
    const card = findCard(definition.href);
    if (!card) return null;
    card.classList.add('sv-ai-shortcut');
    card.dataset.routine = definition.key;
    card.dataset.health = 'loading';
    card.removeAttribute('style');
    card.style.background = definition.accent === 'purple' ? '#7e22ce' : '#c2410c';
    card.style.color = '#fff';
    card.style.textDecoration = 'none';
    card.style.borderRadius = '12px';
    card.innerHTML = '';

    const icon = document.createElement('span');
    icon.className = 'sv-ai-shortcut-icon';
    icon.setAttribute('aria-hidden', 'true');
    icon.textContent = definition.icon;
    const copy = document.createElement('span');
    copy.className = 'sv-ai-shortcut-copy';
    const title = document.createElement('strong');
    title.textContent = definition.title;
    const subtitle = document.createElement('small');
    subtitle.textContent = definition.subtitle;
    const status = document.createElement('span');
    status.dataset.routineStatus = definition.key;
    status.textContent = 'Carregando resumo operacional...';
    copy.append(title, subtitle, status);
    const go = document.createElement('span');
    go.className = 'sv-ai-shortcut-go';
    go.setAttribute('aria-hidden', 'true');
    go.textContent = '→';
    card.append(icon, copy, go);
    card.setAttribute('aria-label', `${definition.title}. ${definition.subtitle}`);
    return card;
  }

  function plural(value, singular, pluralForm) {
    return `${value} ${value === 1 ? singular : pluralForm}`;
  }

  function updateCard(card, data, key) {
    if (!card) return;
    const status = card.querySelector(`[data-routine-status="${key}"]`);
    if (!status) return;
    const pending = Number(data?.pending || 0);
    const ready = Number(data?.ready || 0);
    const failed = Number(data?.failed || 0);
    status.textContent = `${plural(pending, 'pendente', 'pendentes')} · ${plural(ready, 'pronto', 'prontos')} · ${plural(failed, 'falha', 'falhas')}`;
    card.dataset.health = failed > 0 ? 'failure' : pending > 0 ? 'warning' : 'ok';
    card.setAttribute('aria-label', `${card.querySelector('strong')?.textContent || 'Rotina'}. ${status.textContent}. Abrir rotina.`);
  }

  const cards = Object.fromEntries(definitions.map((definition) => [definition.key, installCard(definition)]));
  const parent = cards.images?.parentElement || cards.catalog?.parentElement;
  parent?.classList.add('sv-ai-shortcut-grid');

  fetch('/admin/api/ai-routine-summary.php', { credentials: 'same-origin', headers: { Accept: 'application/json' } })
    .then(async (response) => {
      if (response.redirected || /\/auth\/login\.php/i.test(response.url || '')) throw new Error('Sessão expirada');
      const data = await response.json();
      if (!response.ok || data?.success === false) throw new Error(data?.error || `HTTP ${response.status}`);
      return data;
    })
    .then((data) => {
      updateCard(cards.images, data.images, 'images');
      updateCard(cards.catalog, data.catalog, 'catalog');
    })
    .catch(() => {
      definitions.forEach((definition) => {
        const card = cards[definition.key];
        const status = card?.querySelector(`[data-routine-status="${definition.key}"]`);
        if (status) status.textContent = 'Abrir rotina e consultar o status';
        if (card) card.dataset.health = 'warning';
      });
    });
})();

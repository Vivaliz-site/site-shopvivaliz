(() => {
  'use strict';
  if (location.pathname !== '/admin/catalog-optimization/admin_catalog.php') return;

  const $ = (selector, root = document) => root.querySelector(selector);
  const $$ = (selector, root = document) => [...root.querySelectorAll(selector)];

  const style = document.createElement('style');
  style.id = 'sv-catalog-effective-results-style';
  style.textContent = `
    :root{--sv-impact:#c2410c;--sv-impact-dark:#9a3412;--sv-impact-bg:#fff7ed;--sv-impact-line:#fdba74}
    .sv-impact{margin:0 0 12px;border:1px solid var(--sv-impact-line);border-radius:11px;background:#fff;overflow:hidden}.sv-impact-head{display:flex;justify-content:space-between;gap:10px;padding:12px;background:var(--sv-impact-bg);color:var(--sv-impact-dark)}.sv-impact-head strong,.sv-impact-head small{display:block}.sv-impact-head small{margin-top:3px}.sv-impact-count,.sv-impact-badge{height:max-content;padding:4px 8px;border:1px solid var(--sv-impact-line);border-radius:999px;background:#fff;font-size:11px;font-weight:900;white-space:nowrap}.sv-impact-badge{display:inline-flex;margin-left:5px;background:#ffedd5}.sv-impact-badge.none{border-color:#e2e8f0;background:#f1f5f9;color:#64748b}
    .sv-impact-body{display:grid;gap:9px;padding:12px}.sv-impact-row{border:1px solid #fed7aa;border-left:4px solid var(--sv-impact);border-radius:9px;padding:10px}.sv-impact-title{display:flex;justify-content:space-between;gap:8px;flex-wrap:wrap;margin-bottom:8px}.sv-impact-title span{padding:3px 7px;border-radius:999px;background:#ffedd5;color:var(--sv-impact-dark);font-size:11px;font-weight:850}.sv-impact-compare{display:grid;grid-template-columns:1fr 1fr;gap:8px}.sv-impact-value{padding:8px;border:1px solid #e2e8f0;border-radius:8px;background:#f8fafc;min-width:0}.sv-impact-value.new{border-color:#fed7aa;background:#fff7ed}.sv-impact-value b{display:block;margin-bottom:4px;font-size:11px;text-transform:uppercase;color:#64748b}.sv-impact-value div{margin:0;white-space:pre-wrap;overflow-wrap:anywhere;font-size:13px;line-height:1.45}.sv-impact-empty{padding:11px;border:1px solid #e2e8f0;border-radius:8px;background:#f8fafc;color:#475569;font-size:13px}
    .sv-impact-advanced{border:1px solid #e2e8f0;border-radius:9px;background:#fbfcfe;overflow:hidden}.sv-impact-advanced>summary{padding:10px;cursor:pointer;font-size:12px;font-weight:850;color:#475569}.sv-impact-advanced-body{display:grid;gap:6px;padding:0 10px 10px}.sv-impact-advanced-row{display:grid;grid-template-columns:minmax(120px,.4fr) 1fr;gap:7px;padding:7px;border:1px solid #edf2f7;border-radius:7px;background:#fff;font-size:12px}.sv-impact-hidden{display:none!important}.sv-impact-open{border:0;border-radius:8px;padding:9px 11px;background:#eef2f7;color:#334155;font:inherit;font-weight:850;cursor:pointer}.sv-review-card.ci-only-changed .ci-field.same{display:none!important}
    @media(max-width:720px){.sv-impact-compare,.sv-impact-advanced-row{grid-template-columns:1fr}.sv-review-filters{position:sticky;top:0;z-index:18;overflow-x:auto;flex-wrap:nowrap!important;padding:7px 0;background:rgba(246,248,251,.96);backdrop-filter:blur(8px)}.sv-review-filters>*{flex:0 0 auto}.sv-review-filters .sv-search{flex:0 0 min(78vw,330px)!important}.sv-review-summary{grid-template-columns:auto minmax(0,1fr)!important;padding:11px!important}.sv-review-actions{grid-column:2/-1!important;justify-content:flex-start!important}.sv-bulk-bar{bottom:calc(7px + env(safe-area-inset-bottom))!important;width:calc(100% - 14px)!important}.sv-bulk-actions{display:grid!important;grid-template-columns:1fr 1fr!important;width:100%}.sv-bulk-actions .apply{grid-column:1/-1}.sv-bulk-actions button{min-height:44px;width:100%}}
  `;
  document.head.appendChild(style);

  function valueBox(label, value, kind = '') {
    const box = document.createElement('div');
    box.className = `sv-impact-value ${kind}`.trim();
    const caption = document.createElement('b');
    caption.textContent = label;
    const content = document.createElement('div');
    content.textContent = String(value ?? '').trim() || '—';
    box.append(caption, content);
    return box;
  }

  function changedFields(article) {
    return $$('.ci-field.changed,.ci-field.new,.ci-field.removed', article).map((field) => {
      const input = $('input,textarea,select', field);
      return {
        label: $('.ci-label-title', field)?.textContent?.trim() || 'Campo',
        state: field.classList.contains('new') ? 'Novo' : field.classList.contains('removed') ? 'Removido' : 'Alterado',
        before: input?.dataset.ciOriginal || '',
        after: input?.value || ''
      };
    });
  }

  function unchangedFields(article) {
    return $$('.ci-field.same', article).map((field) => $('.ci-label-title', field)?.textContent?.trim() || 'Campo');
  }

  function renderArticle(article) {
    const panel = $('.sv-impact', article);
    if (!panel) return;
    const fields = changedFields(article);
    const unchanged = unchangedFields(article);
    const count = fields.length;
    $('.sv-impact-count', panel).textContent = `${count} mudança${count === 1 ? '' : 's'}`;
    const title = $('.sv-review-title strong', article);
    if (title) {
      let badge = $('.sv-impact-badge', article);
      if (!badge) {
        badge = document.createElement('span');
        title.insertAdjacentElement('afterend', badge);
      }
      badge.className = `sv-impact-badge${count ? '' : ' none'}`;
      badge.textContent = count ? `${count} mudança${count === 1 ? '' : 's'}` : 'sem mudança';
    }
    const body = $('.sv-impact-body', panel);
    body.innerHTML = '';
    if (!count) {
      const empty = document.createElement('div');
      empty.className = 'sv-impact-empty';
      empty.textContent = 'Nenhum campo editorial foi alterado neste rascunho.';
      body.appendChild(empty);
    } else {
      fields.forEach((field) => {
        const row = document.createElement('div');
        row.className = 'sv-impact-row';
        const titleLine = document.createElement('div');
        titleLine.className = 'sv-impact-title';
        const name = document.createElement('strong');
        name.textContent = field.label;
        const state = document.createElement('span');
        state.textContent = field.state;
        titleLine.append(name, state);
        const compare = document.createElement('div');
        compare.className = 'sv-impact-compare';
        compare.append(valueBox('Atual', field.before), valueBox('Novo', field.after, 'new'));
        row.append(titleLine, compare);
        body.appendChild(row);
      });
    }
    if (unchanged.length) {
      const details = document.createElement('details');
      details.className = 'sv-impact-advanced';
      const summary = document.createElement('summary');
      summary.textContent = `Detalhes avançados · ${unchanged.length} campo(s) sem mudança`;
      const detailBody = document.createElement('div');
      detailBody.className = 'sv-impact-advanced-body';
      unchanged.forEach((labelText) => {
        const row = document.createElement('div');
        row.className = 'sv-impact-advanced-row';
        const label = document.createElement('strong');
        label.textContent = labelText;
        const status = document.createElement('span');
        status.textContent = 'Sem mudança';
        row.append(label, status);
        detailBody.appendChild(row);
      });
      details.append(summary, detailBody);
      body.appendChild(details);
    }
  }

  function enhanceArticle(article) {
    if (article.dataset.svImpact === '1') return;
    const body = $('.sv-review-body', article);
    const intelligence = $('.ci-summary', article);
    if (!body || !intelligence) return;
    article.dataset.svImpact = '1';
    article.classList.add('ci-only-changed');
    const originalToggle = $('[data-ci-only]', article);
    if (originalToggle) originalToggle.textContent = 'Mostrar todos os campos';
    const panel = document.createElement('section');
    panel.className = 'sv-impact';
    panel.innerHTML = '<div class="sv-impact-head"><div><strong>O que realmente será alterado</strong><small>Somente campos diferentes do cadastro atual.</small></div><span class="sv-impact-count">0 mudanças</span></div><div class="sv-impact-body"></div>';
    intelligence.insertAdjacentElement('afterend', panel);
    $$('input,textarea,select', article).forEach((input) => input.addEventListener('input', () => renderArticle(article)));
    renderArticle(article);
  }

  function addControls(articles) {
    const filters = $('.sv-review-filters');
    if (!filters || filters.dataset.svImpactControls === '1') return;
    filters.dataset.svImpactControls = '1';
    const fail = document.createElement('button');
    fail.type = 'button'; fail.className = 'sv-chip'; fail.dataset.effectiveFilter = 'fail'; fail.textContent = 'Falhas';
    const selected = document.createElement('button');
    selected.type = 'button'; selected.className = 'sv-chip'; selected.dataset.effectiveFilter = 'selected'; selected.textContent = 'Selecionados';
    const open = document.createElement('button');
    open.type = 'button'; open.className = 'sv-impact-open'; open.textContent = 'Abrir exibidos';
    const close = document.createElement('button');
    close.type = 'button'; close.className = 'sv-impact-open'; close.textContent = 'Fechar todos';
    const anchor = $('#sv-review-count', filters);
    [fail, selected, open, close].forEach((node) => filters.insertBefore(node, anchor));
    let active = 'all';
    const apply = () => {
      articles.forEach((article) => {
        const show = active === 'all' || (active === 'fail' && article.dataset.svState === 'fail') || (active === 'selected' && Boolean($('input[name="selected_ids[]"]', article)?.checked));
        article.classList.toggle('sv-impact-hidden', !show);
      });
      [fail, selected].forEach((button) => button.classList.toggle('is-active', button.dataset.effectiveFilter === active));
    };
    [fail, selected].forEach((button) => button.addEventListener('click', () => {
      const requested = active === button.dataset.effectiveFilter ? 'all' : button.dataset.effectiveFilter;
      $('[data-review-filter="all"]')?.click();
      active = requested;
      apply();
    }));
    $$('[data-review-filter]', filters).forEach((button) => button.addEventListener('click', () => { active = 'all'; apply(); }));
    articles.forEach((article) => $('input[name="selected_ids[]"]', article)?.addEventListener('change', () => { if (active === 'selected') apply(); }));
    open.addEventListener('click', () => articles.filter((article) => !article.classList.contains('sv-hidden') && !article.classList.contains('sv-impact-hidden')).forEach((article) => { article.classList.add('is-open'); const button = $('.sv-toggle', article); if (button) button.textContent = 'Fechar revisão'; }));
    close.addEventListener('click', () => articles.forEach((article) => { article.classList.remove('is-open'); const button = $('.sv-toggle', article); if (button) button.textContent = 'Revisar'; }));
  }

  function boot() {
    const articles = $$('.sv-review-card');
    if (!articles.length) return;
    articles.forEach(enhanceArticle);
    addControls(articles);
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot, { once: true });
  else boot();
})();

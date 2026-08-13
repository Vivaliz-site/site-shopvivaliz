(() => {
  'use strict';
  if (location.pathname !== '/admin/ai-image-studio/admin_validate.php') return;

  const $ = (selector, root = document) => root.querySelector(selector);
  const $$ = (selector, root = document) => [...root.querySelectorAll(selector)];
  const cache = new Map();
  const requested = new Set();
  let requestScheduled = false;

  const style = document.createElement('style');
  style.textContent = `
    .iv-source-audit{margin:9px 0;padding:9px 10px;border-radius:9px;border:1px solid #bfdbfe;background:#eff6ff;color:#075985;font-size:12px;font-weight:800}
    .iv-source-audit.bad{border-color:#fecaca;background:#fff1f1;color:#991b1b}
    .iv-source-audit code{overflow-wrap:anywhere;font-weight:700}
  `;
  document.head.appendChild(style);

  function stagingId(card) {
    const input = $('input[name="selected_ids[]"]', card) || $('input[name="staging_id"]', card);
    return Number(input?.value || 0);
  }

  function ensureNotice(card) {
    let notice = $('.iv-source-audit', card);
    if (!notice) {
      notice = document.createElement('div');
      notice.className = 'iv-source-audit';
      const body = $('.iv-review-body', card) || card;
      body.insertBefore(notice, body.firstChild || null);
    }
    return notice;
  }

  function markUnavailable(card, message) {
    card.dataset.sourceAuditable = '0';
    card.dataset.state = 'blocked';
    const notice = ensureNotice(card);
    notice.className = 'iv-source-audit bad';
    notice.textContent = message;
    const publish = $('button.publish', card);
    if (publish) {
      publish.disabled = true;
      publish.title = 'Publicação bloqueada até existir referência visual auditável.';
    }
  }

  function applySource(card, item) {
    if (!item?.source_auditable || !item?.source_image_ref) {
      markUnavailable(card, 'Referência visual não persistida. Esta imagem precisa ser gerada novamente pelo dashboard antes de qualquer publicação.');
      return;
    }

    card.dataset.sourceAuditable = '1';
    card.dataset.sourceJobId = String(Number(item.source_job_id || 0));
    card.dataset.productId = String(Number(item.product_id || 0));
    const before = $('.compare img[alt="Foto real do produto"]', card);
    if (before) {
      before.src = item.source_image_ref;
      before.dataset.auditSource = 'staging';
    }
    const heading = $$('.compare h3', card).find((node) => /Antes/i.test(node.textContent || ''));
    if (heading) heading.textContent = 'Antes — foto real usada na geração';

    const notice = ensureNotice(card);
    notice.className = 'iv-source-audit';
    const job = Number(item.source_job_id || 0);
    notice.textContent = job > 0
      ? `Referência visual auditável registrada pelo job #${job}. A comparação abaixo usa exatamente a fonte fornecida ao modelo.`
      : 'Referência visual auditável registrada no staging. A comparação abaixo usa exatamente a fonte fornecida ao modelo.';
  }

  async function fetchBatch(ids) {
    const response = await fetch(`/admin/ai-image-studio/api/review_sources.php?ids=${encodeURIComponent(ids.join(','))}`, {
      credentials: 'same-origin',
      headers: {'Accept': 'application/json'},
    });
    if (response.redirected || /\/auth\/login\.php/i.test(response.url || '')) throw new Error('Sessão administrativa expirada.');
    const data = await response.json();
    if (!response.ok || data?.success === false) throw new Error(data?.error || `Falha HTTP ${response.status}.`);
    for (const item of data.items || []) cache.set(Number(item.staging_id), item);
  }

  async function loadPending() {
    requestScheduled = false;
    const cards = $$('.iv-review-card, article.card').filter((card) => stagingId(card) > 0);
    const ids = [...new Set(cards.map(stagingId).filter((id) => id > 0 && !cache.has(id) && !requested.has(id)))];
    if (!ids.length) {
      cards.forEach((card) => {
        const id = stagingId(card);
        if (cache.has(id)) applySource(card, cache.get(id));
      });
      return;
    }
    ids.forEach((id) => requested.add(id));
    try {
      for (let offset = 0; offset < ids.length; offset += 100) await fetchBatch(ids.slice(offset, offset + 100));
    } catch (error) {
      cards.forEach((card) => {
        const id = stagingId(card);
        if (ids.includes(id)) markUnavailable(card, `Não foi possível comprovar a foto de referência: ${error.message}`);
      });
    }
    cards.forEach((card) => {
      const id = stagingId(card);
      if (cache.has(id)) applySource(card, cache.get(id));
      else if (ids.includes(id)) markUnavailable(card, 'O staging não retornou uma referência visual auditável. Gere novamente antes de publicar.');
    });
  }

  function scheduleLoad() {
    if (requestScheduled) return;
    requestScheduled = true;
    setTimeout(loadPending, 30);
  }

  document.addEventListener('click', (event) => {
    const target = event.target instanceof Element ? event.target : null;
    if (!target) return;
    const publish = target.closest('button.publish');
    if (publish) {
      const card = publish.closest('.iv-review-card, article.card');
      if (!card || card.dataset.sourceAuditable === '1') return;
      event.preventDefault();
      event.stopImmediatePropagation();
      markUnavailable(card, 'Publicação bloqueada: primeiro comprove a mesma foto real que foi fornecida à IA.');
      return;
    }

    const regenerate = target.closest('button.regenerate');
    if (regenerate) {
      const card = regenerate.closest('.iv-review-card, article.card');
      const productId = Number(card?.dataset.productId || 0);
      event.preventDefault();
      event.stopImmediatePropagation();
      const suffix = productId > 0 ? `?product_id=${encodeURIComponent(String(productId))}` : '';
      if (window.confirm('Para manter a trilha de auditoria da foto real, a regeneração agora começa no dashboard. Ir para a geração auditável?')) {
        location.href = `/admin/ai-image-studio/admin_dashboard.php${suffix}`;
      }
    }

    const bulkApply = target.closest('.iv-bulk .apply');
    if (bulkApply) {
      const selected = $$('input[name="selected_ids[]"][form="bulk-action-form"]:checked');
      const blocked = selected.filter((input) => input.closest('.iv-review-card, article.card')?.dataset.sourceAuditable !== '1');
      if (!blocked.length) return;
      event.preventDefault();
      event.stopImmediatePropagation();
      window.alert(`${blocked.length} imagem(ns) selecionada(s) não possuem referência visual auditável. Gere-as novamente antes de aplicar.`);
    }
  }, true);

  document.addEventListener('submit', (event) => {
    const form = event.target instanceof HTMLFormElement ? event.target : null;
    if (!form) return;
    const submitter = event.submitter instanceof HTMLElement ? event.submitter : null;
    if (submitter?.classList.contains('publish')) {
      const card = form.closest('.iv-review-card, article.card');
      if (card?.dataset.sourceAuditable === '1') return;
      event.preventDefault();
      event.stopImmediatePropagation();
      markUnavailable(card, 'Publicação bloqueada: a foto real usada na geração não foi comprovada.');
      return;
    }
    if (form.id === 'bulk-action-form') {
      const action = $('select[name="bulk_action"]', form)?.value || '';
      if (action !== 'bulk_publish') return;
      const selected = $$('input[name="selected_ids[]"][form="bulk-action-form"]:checked');
      if (selected.every((input) => input.closest('.iv-review-card, article.card')?.dataset.sourceAuditable === '1')) return;
      event.preventDefault();
      event.stopImmediatePropagation();
      window.alert('O lote contém imagem sem referência visual auditável. A publicação foi bloqueada.');
    }
  }, true);

  scheduleLoad();
  const observer = new MutationObserver(scheduleLoad);
  observer.observe(document.documentElement, {childList: true, subtree: true});
  setTimeout(() => observer.disconnect(), 20000);
})();

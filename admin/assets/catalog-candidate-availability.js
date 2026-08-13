(() => {
  'use strict';

  const PAGE = '/admin/catalog-optimization/admin_catalog.php';
  if (window.location.pathname.replace(/\/+$/, '') !== PAGE) return;

  const $ = (selector, root = document) => root.querySelector(selector);
  let requestGeneration = 0;
  let refreshTimer = 0;
  let observer = null;
  let patching = false;
  let lastSummary = null;

  const style = document.createElement('style');
  style.id = 'sv-candidate-availability-style';
  style.textContent = `
    .sv-candidate-review-chip{background:#fff7ed!important;border-color:#fed7aa!important;color:#9a3412!important}
    .sv-candidate-zero-state{display:grid;justify-items:center;gap:8px;padding:18px;text-align:center;color:#475569}
    .sv-candidate-zero-state strong{color:#172033;font-size:15px}.sv-candidate-zero-state span{max-width:720px;line-height:1.45}
    .sv-candidate-zero-actions{display:flex;gap:8px;justify-content:center;flex-wrap:wrap}
    .sv-candidate-zero-actions button{border:0;border-radius:9px;min-height:42px;padding:9px 12px;font:inherit;font-weight:850;cursor:pointer;background:#eef2f7;color:#334155}
    .sv-candidate-zero-actions .primary{background:#7e22ce;color:#fff}
  `;
  document.head.appendChild(style);

  function parameters() {
    const channel = $('#channel')?.value || 'ml';
    const rawLimit = Number($('#load-limit')?.value || 0);
    const limit = Math.max(0, Math.min(5000, Number.isFinite(rawLimit) ? rawLimit : 0));
    return { channel, limit };
  }

  function addReviewChip(summary) {
    const host = $('#candidate-summary');
    if (!host) return;
    let chip = $('[data-sv-candidate-review-chip]', host);
    const inReview = Number(summary.in_review || 0);
    if (inReview <= 0) {
      chip?.remove();
      return;
    }
    if (!chip) {
      chip = document.createElement('span');
      chip.dataset.svCandidateReviewChip = '1';
      chip.className = 'sv-candidate-review-chip';
      host.appendChild(chip);
    }
    chip.innerHTML = `<strong>${inReview}</strong> em revisão`;
    chip.title = 'Produtos com rascunho aberto, publicação em andamento ou falha de publicação para este canal.';
  }

  function renderZeroState(summary) {
    const list = $('#candidate-list');
    if (!list || Number(summary.eligible || 0) > 0) return;
    const empty = $('.sv-empty', list);
    if (!empty) return;

    const active = Number(summary.active || 0);
    const inReview = Number(summary.in_review || 0);
    empty.className = 'sv-empty sv-candidate-zero-state';
    empty.innerHTML = '';

    const title = document.createElement('strong');
    const explanation = document.createElement('span');
    const actions = document.createElement('div');
    actions.className = 'sv-candidate-zero-actions';

    if (active <= 0) {
      title.textContent = 'Nenhum produto ativo foi encontrado';
      explanation.textContent = 'A lista de produtos ativos do site precisa ser sincronizada com o Olist/Tiny antes de executar esta rotina.';
    } else if (inReview > 0) {
      title.textContent = 'Todos os produtos deste canal já estão em revisão';
      explanation.textContent = `${inReview} produto${inReview === 1 ? '' : 's'} possui${inReview === 1 ? '' : 'em'} rascunho aberto ou processamento em andamento. Revise, aplique, rejeite ou reprocese esses itens para liberá-los novamente.`;
      const review = document.createElement('button');
      review.type = 'button';
      review.className = 'primary';
      review.textContent = 'Ir para Revisar e aplicar';
      review.addEventListener('click', () => {
        ($('#sv-review') || $('.sv-review-heading'))?.scrollIntoView({ behavior: 'smooth', block: 'start' });
      });
      actions.appendChild(review);
    } else {
      title.textContent = 'Nenhum produto elegível neste canal';
      explanation.textContent = 'Atualize a lista. Caso o problema continue, verifique a sincronização do catálogo e os estados dos rascunhos.';
    }

    const retry = document.createElement('button');
    retry.type = 'button';
    retry.textContent = 'Atualizar lista';
    retry.addEventListener('click', () => $('#load-candidates')?.click());
    actions.appendChild(retry);
    empty.append(title, explanation, actions);
  }

  function patch(summary) {
    patching = true;
    try {
      addReviewChip(summary);
      renderZeroState(summary);
    } finally {
      patching = false;
    }
  }

  async function refresh() {
    const generation = ++requestGeneration;
    const { channel, limit } = parameters();
    try {
      const query = new URLSearchParams({ channel, limit: String(limit) });
      const response = await fetch(`/admin/catalog-optimization/api/pending_candidates_unique.php?${query}`, {
        credentials: 'same-origin',
        headers: { Accept: 'application/json' },
      });
      if (response.redirected || /\/auth\/login\.php/i.test(response.url || '')) return;
      const data = await response.json();
      if (generation !== requestGeneration || !response.ok || data?.success === false) return;
      const summary = data?.summary;
      if (!summary || typeof summary !== 'object') return;
      lastSummary = summary;
      patch(summary);
    } catch (_) {
      // O controlador principal continua exibindo o erro operacional da API.
    }
  }

  function schedule(delay = 120) {
    window.clearTimeout(refreshTimer);
    refreshTimer = window.setTimeout(refresh, delay);
  }

  function start() {
    if (!$('#candidate-list') || !$('#candidate-summary')) {
      window.setTimeout(start, 120);
      return;
    }
    $('#channel')?.addEventListener('change', () => schedule(80));
    $('#load-candidates')?.addEventListener('click', () => schedule(180));
    $('#load-limit')?.addEventListener('change', () => schedule(120));

    observer = new MutationObserver(() => {
      if (patching || !lastSummary) return;
      window.requestAnimationFrame(() => patch(lastSummary));
    });
    observer.observe($('#candidate-list'), { childList: true, subtree: true });
    observer.observe($('#candidate-summary'), { childList: true, subtree: true });
    schedule(40);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', start, { once: true });
  } else {
    start();
  }
})();

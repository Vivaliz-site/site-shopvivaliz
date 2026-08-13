(() => {
  'use strict';

  const path = location.pathname.replace(/\/+$/, '');
  const DASH = '/admin/ai-image-studio/admin_dashboard.php';
  const REVIEW = '/admin/ai-image-studio/admin_validate.php';
  if (![DASH, REVIEW].includes(path)) return;

  const $ = (selector, root = document) => root.querySelector(selector);
  const $$ = (selector, root = document) => [...root.querySelectorAll(selector)];

  // O endpoint autenticado aceita no maximo 100 jobs por consulta. Mantemos o
  // controlador principal simples, mas o transporte divide lotes maiores e
  // recompõe a resposta sem perder jobs 101+ durante execucoes amplas.
  if (path === DASH && !window.__svImageStatusChunkingInstalled) {
    window.__svImageStatusChunkingInstalled = true;
    const originalFetch = window.fetch.bind(window);
    window.fetch = async (input, init) => {
      let url;
      try {
        const raw = typeof input === 'string' ? input : input?.url;
        url = new URL(raw, location.origin);
      } catch (_) {
        return originalFetch(input, init);
      }
      if (url.origin !== location.origin || !/\/admin\/ai-image-studio\/api\/generation_status\.php$/i.test(url.pathname)) {
        return originalFetch(input, init);
      }

      const ids = String(url.searchParams.get('job_ids') || url.searchParams.get('ids') || '')
        .split(/[\s,]+/)
        .map((value) => Number(value))
        .filter((value) => Number.isInteger(value) && value > 0);
      const unique = [...new Set(ids)];
      if (unique.length <= 100) return originalFetch(input, init);

      const chunks = [];
      for (let offset = 0; offset < unique.length; offset += 100) {
        chunks.push(unique.slice(offset, offset + 100));
      }
      const responses = [];
      for (const chunk of chunks) {
        const chunkUrl = new URL(url.href);
        chunkUrl.searchParams.delete('ids');
        chunkUrl.searchParams.set('job_ids', chunk.join(','));
        const response = await originalFetch(chunkUrl.href, init);
        responses.push(response);
        if (!response.ok || response.redirected || /\/auth\/login\.php/i.test(response.url || '')) {
          return response;
        }
      }

      const payloads = await Promise.all(responses.map((response) => response.json()));
      const jobs = payloads.flatMap((payload) => Array.isArray(payload?.jobs) ? payload.jobs : []);
      const merged = {
        success: payloads.every((payload) => payload?.success !== false),
        jobs,
        summary: {
          total: jobs.length,
          queued: jobs.filter((job) => job.status === 'queued').length,
          running: jobs.filter((job) => job.status === 'running').length,
          done: jobs.filter((job) => job.status === 'done').length,
          failed: jobs.filter((job) => job.status === 'failed').length,
          unknown: jobs.filter((job) => job.status === 'unknown').length,
          partial_failure: jobs.filter((job) => job.result_state === 'partial_failure').length,
        },
        request: {
          requested_job_count: unique.length,
          returned_job_count: jobs.length,
          limit: 100,
          chunks: chunks.length,
          truncated: false,
        },
        queue: payloads.at(-1)?.queue ?? null,
      };
      return new Response(JSON.stringify(merged), {
        status: 200,
        headers: {'Content-Type': 'application/json; charset=UTF-8', 'Cache-Control': 'no-store'},
      });
    };
  }

  if (path !== REVIEW) return;

  const style = document.createElement('style');
  style.textContent = `
    .iv-review-body label.confirm.iv-visual-confirm{display:flex!important;align-items:flex-start;gap:8px;margin:12px 0;padding:10px 11px;border:1px solid #bfd7ec;border-radius:9px;background:#f7fbff;font-weight:800;color:#0f4f82}
    .iv-review-body label.confirm.iv-visual-confirm input{margin-top:2px;width:18px;height:18px;accent-color:#1769aa}
    .iv-bulk-visual-confirm{display:flex;align-items:flex-start;gap:7px;font-size:12px;font-weight:800;color:#0f4f82;max-width:380px}
    .iv-bulk-visual-confirm input{margin-top:1px;width:17px;height:17px;accent-color:#1769aa}
  `;
  document.head.appendChild(style);

  function enforceCard(card) {
    if (card.dataset.ivVisualConfirmReady === '1') return;
    const confirmInput = $('input[name="confirm_channel"]', card);
    const publish = $('button.publish[name="action"], button.publish', card);
    if (!confirmInput || !publish) return;
    card.dataset.ivVisualConfirmReady = '1';

    const label = confirmInput.closest('label.confirm');
    if (label) {
      label.classList.remove('iv-hidden');
      label.classList.add('iv-visual-confirm');
      while (confirmInput.nextSibling) confirmInput.nextSibling.remove();
      label.appendChild(document.createTextNode(' Confirmo que comparei visualmente a imagem gerada com a foto real e validei identidade, cor, forma, proporção, acessórios, composição e o marketplace de destino.'));
    }

    // Capture ocorre antes do handler do controlador. Este helper nunca envia
    // o formulario: ele apenas veta a publicacao ate a confirmacao humana ter
    // sido marcada explicitamente pelo operador.
    publish.addEventListener('click', (event) => {
      if (publish.disabled || confirmInput.checked) return;
      event.preventDefault();
      event.stopImmediatePropagation();
      label?.scrollIntoView({behavior: 'smooth', block: 'center'});
      confirmInput.focus();
      window.alert('Marque a confirmação de revisão visual antes de publicar. A confirmação não é preenchida automaticamente.');
    }, true);
  }

  function enforceBulk() {
    const bar = $('.iv-bulk');
    if (!bar || bar.dataset.ivVisualConfirmReady === '1') return;
    const apply = $('.apply', bar);
    if (!apply) return;
    bar.dataset.ivVisualConfirmReady = '1';
    const label = document.createElement('label');
    label.className = 'iv-bulk-visual-confirm';
    label.innerHTML = '<input type="checkbox" id="iv-bulk-visual-review"> <span>Confirmo que revisei visualmente cada imagem selecionada contra a respectiva foto real e o canal de destino.</span>';
    bar.insertBefore(label, $('.iv-bulk-actions', bar) || null);
    const checkbox = $('input', label);
    apply.addEventListener('click', (event) => {
      if (checkbox.checked) return;
      event.preventDefault();
      event.stopImmediatePropagation();
      checkbox.focus();
      window.alert('Confirme explicitamente a revisão visual de todas as imagens selecionadas antes da aplicação em lote.');
    }, true);
  }

  const enforce = () => {
    $$('.iv-review-card').forEach(enforceCard);
    enforceBulk();
  };
  enforce();
  const observer = new MutationObserver(enforce);
  observer.observe(document.documentElement, {childList: true, subtree: true});
  setTimeout(() => observer.disconnect(), 15000);
})();

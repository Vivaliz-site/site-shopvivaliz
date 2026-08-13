(() => {
  'use strict';

  const path = location.pathname.replace(/\/+$/, '');
  const DASH = '/admin/ai-image-studio/admin_dashboard.php';
  const REVIEW = '/admin/ai-image-studio/admin_validate.php';
  if (![DASH, REVIEW].includes(path)) return;

  const $ = (selector, root = document) => root.querySelector(selector);
  const $$ = (selector, root = document) => [...root.querySelectorAll(selector)];
  const STRICT_WHITE_CHANNELS = new Set(['ml', 'amazon', 'tiktok']);

  if (path === DASH && !window.__svImageStatusChunkingInstalled) {
    window.__svImageStatusChunkingInstalled = true;
    const originalFetch = window.fetch.bind(window);
    const trackedJobs = new Map();

    window.fetch = async (input, init) => {
      let url;
      try {
        const raw = typeof input === 'string' ? input : input?.url;
        url = new URL(raw, location.origin);
      } catch (_) {
        return originalFetch(input, init);
      }

      const sameOrigin = url.origin === location.origin;
      const isStatus = sameOrigin && /\/admin\/ai-image-studio\/api\/generation_status\.php$/i.test(url.pathname);
      const isEnqueue = sameOrigin && /\/admin\/ai-image-studio\/api\/enqueue_generation\.php$/i.test(url.pathname);

      if (isEnqueue) {
        const response = await originalFetch(input, init);
        try {
          const payload = await response.clone().json();
          const jobId = Number(payload?.job_id || 0);
          const productId = Number(payload?.product_id || 0);
          if (jobId > 0 && productId > 0) trackedJobs.set(jobId, productId);
        } catch (_) {}
        return response;
      }

      if (!isStatus) return originalFetch(input, init);

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
        if (!response.ok || response.redirected || /\/auth\/login\.php/i.test(response.url || '')) return response;
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

    // O controlador principal atualiza a cada poucos segundos. Este monitor e
    // uma rede de seguranca de longa duracao: continua consultando enquanto
    // houver jobs rastreados, inclusive depois do limite visual do loop local.
    setInterval(async () => {
      if (trackedJobs.size === 0) return;
      const ids = [...trackedJobs.keys()];
      try {
        const response = await window.fetch(`/admin/ai-image-studio/api/generation_status.php?job_ids=${encodeURIComponent(ids.join(','))}`, {credentials: 'same-origin'});
        if (!response.ok || response.redirected) return;
        const data = await response.json();
        if (data?.success === false) return;
        let terminal = 0;
        let issues = 0;
        for (const job of data.jobs || []) {
          const jobId = Number(job.job_id || 0);
          const productId = trackedJobs.get(jobId);
          if (!productId) continue;
          const row = $(`.iv-progress-row[data-id="${CSS.escape(String(productId))}"]`);
          const state = row ? $('strong', row) : null;
          if (state) {
            if (job.result_state === 'ready_for_review') {
              state.className = 'done';
              state.textContent = 'Pronto para revisão';
            } else if (job.result_state === 'partial_failure' || job.result_state === 'failed') {
              issues++;
              state.className = 'failed';
              state.textContent = job.result_state === 'partial_failure' ? 'Parcial — revisar variantes' : 'Falhou';
            } else if (job.status === 'running') {
              state.className = 'running';
              state.textContent = 'Gerando...';
            } else {
              state.className = 'queued';
              state.textContent = 'Na fila';
            }
          }
          if (job.terminal) terminal++;
        }
        const count = $('#iv-progress-count');
        if (count) count.textContent = `${terminal}/${ids.length} concluído(s)`;
        if (terminal >= ids.length && ids.length > 0) {
          const title = $('#iv-progress-title');
          if (title) title.textContent = issues ? 'Geração concluída com pendências — revise as variantes antes de publicar' : 'Geração concluída — revise antes de publicar';
          const actions = $('.iv-actions', $('.iv-actionbar'));
          if (actions && !$('#iv-review')) {
            actions.insertAdjacentHTML('beforeend', '<button id="iv-review" class="iv-primary">Revisar resultados</button>');
            $('#iv-review')?.addEventListener('click', () => { location.href = REVIEW; });
          }
          trackedJobs.clear();
        }
      } catch (_) {}
    }, 30000);

    // providerInfo() atualiza programaticamente o modelo no proprio select.
    // O listener delegado roda depois dele e persiste o modelo correspondente
    // ao novo provedor, evitando restaurar um override incompatível no reload.
    document.addEventListener('change', (event) => {
      if (!(event.target instanceof HTMLSelectElement) || event.target.id !== 'iv-provider') return;
      setTimeout(() => {
        const provider = $('#iv-provider');
        const model = $('#iv-model');
        if (!provider || !model) return;
        sessionStorage.setItem('ivProvider', provider.value);
        sessionStorage.setItem('ivModel', model.value || '');
      }, 0);
    });
  }

  if (path !== REVIEW) return;

  const style = document.createElement('style');
  style.textContent = `
    .iv-review-body label.confirm.iv-visual-confirm{display:flex!important;align-items:flex-start;gap:8px;margin:12px 0;padding:10px 11px;border:1px solid #bfd7ec;border-radius:9px;background:#f7fbff;font-weight:800;color:#0f4f82}
    .iv-review-body label.confirm.iv-visual-confirm input{margin-top:2px;width:18px;height:18px;accent-color:#1769aa}
    .iv-bulk-visual-confirm{display:flex;align-items:flex-start;gap:7px;font-size:12px;font-weight:800;color:#0f4f82;max-width:380px}
    .iv-bulk-visual-confirm input{margin-top:1px;width:17px;height:17px;accent-color:#1769aa}
    .iv-white-first-block{margin:10px 0;padding:9px 10px;border:1px solid #fecaca;border-radius:8px;background:#fff1f1;color:#991b1b;font-weight:800}
    .iv-source-audit{margin:9px 0;padding:9px 10px;border-radius:9px;border:1px solid #bfdbfe;background:#eff6ff;color:#075985;font-size:12px;font-weight:800}
    .iv-source-audit.bad{border-color:#fecaca;background:#fff1f1;color:#991b1b}
  `;
  document.head.appendChild(style);

  const sourceCache = new Map();
  const sourceRequested = new Set();
  let sourceScheduled = false;

  function stagingId(card) {
    const input = $('input[name="selected_ids[]"]', card) || $('input[name="staging_id"]', card);
    return Number(input?.value || 0);
  }

  function sourceNotice(card) {
    let notice = $('.iv-source-audit', card);
    if (!notice) {
      notice = document.createElement('div');
      notice.className = 'iv-source-audit';
      const body = $('.iv-review-body', card) || card;
      body.insertBefore(notice, body.firstChild || null);
    }
    return notice;
  }

  function blockUnverifiableSource(card, message) {
    card.dataset.sourceAuditable = '0';
    card.dataset.state = 'blocked';
    const notice = sourceNotice(card);
    notice.className = 'iv-source-audit bad';
    notice.textContent = message;
    const publish = $('button.publish', card);
    if (publish) {
      publish.disabled = true;
      publish.title = 'Publicação bloqueada até existir referência visual auditável.';
    }
  }

  function applySourceAudit(card, item) {
    if (!item?.source_auditable || !item?.source_image_ref) {
      blockUnverifiableSource(card, 'Referência visual não persistida. Gere novamente pelo dashboard antes de qualquer publicação.');
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
    const notice = sourceNotice(card);
    notice.className = 'iv-source-audit';
    const job = Number(item.source_job_id || 0);
    notice.textContent = job > 0
      ? `Referência visual auditável registrada pelo job #${job}. A comparação abaixo usa exatamente a fonte fornecida à IA.`
      : 'Referência visual auditável registrada no staging. A comparação abaixo usa exatamente a fonte fornecida à IA.';
  }

  async function fetchSourceBatch(ids) {
    const response = await fetch(`/admin/ai-image-studio/api/review_sources.php?ids=${encodeURIComponent(ids.join(','))}`, {
      credentials: 'same-origin',
      headers: {'Accept': 'application/json'},
    });
    if (response.redirected || /\/auth\/login\.php/i.test(response.url || '')) throw new Error('Sessão administrativa expirada.');
    const data = await response.json();
    if (!response.ok || data?.success === false) throw new Error(data?.error || `Falha HTTP ${response.status}.`);
    for (const item of data.items || []) sourceCache.set(Number(item.staging_id), item);
  }

  async function loadSourceAudit() {
    sourceScheduled = false;
    const cards = $$('.iv-review-card, article.card').filter((card) => stagingId(card) > 0);
    const ids = [...new Set(cards.map(stagingId).filter((id) => id > 0 && !sourceCache.has(id) && !sourceRequested.has(id)))];
    ids.forEach((id) => sourceRequested.add(id));
    try {
      for (let offset = 0; offset < ids.length; offset += 100) await fetchSourceBatch(ids.slice(offset, offset + 100));
    } catch (error) {
      cards.forEach((card) => {
        const id = stagingId(card);
        if (ids.includes(id)) blockUnverifiableSource(card, `Não foi possível comprovar a foto de referência: ${error.message}`);
      });
    }
    cards.forEach((card) => {
      const id = stagingId(card);
      if (sourceCache.has(id)) applySourceAudit(card, sourceCache.get(id));
      else if (ids.includes(id)) blockUnverifiableSource(card, 'O staging não retornou uma referência visual auditável. Gere novamente antes de publicar.');
    });
  }

  function scheduleSourceAudit() {
    if (sourceScheduled) return;
    sourceScheduled = true;
    setTimeout(loadSourceAudit, 30);
  }

  function enforceCard(card) {
    if (card.dataset.ivVisualConfirmReady === '1') return;
    const confirmInput = $('input[name="confirm_channel"]', card);
    const publish = $('button.publish[name="action"], button.publish', card);
    if (!confirmInput || !publish) return;
    card.dataset.ivVisualConfirmReady = '1';

    const channel = String(card.dataset.channel || '').toLowerCase();
    const type = String(card.dataset.type || '').toLowerCase();
    if (STRICT_WHITE_CHANNELS.has(channel) && type === 'cover') {
      publish.disabled = true;
      publish.title = 'Este canal usa white como variante canônica de capa.';
      const note = document.createElement('div');
      note.className = 'iv-white-first-block';
      note.textContent = 'Publicação bloqueada: neste canal a capa canônica é a variante White. Gere/revise White para evitar que Cover assuma a primeira posição da galeria.';
      publish.closest('form')?.insertBefore(note, publish.closest('.actions') || publish);
    }

    const label = confirmInput.closest('label.confirm');
    if (label) {
      label.classList.remove('iv-hidden');
      label.classList.add('iv-visual-confirm');
      while (confirmInput.nextSibling) confirmInput.nextSibling.remove();
      label.appendChild(document.createTextNode(' Confirmo que comparei visualmente a imagem gerada com a foto real e validei identidade, cor, forma, proporção, acessórios, composição e o marketplace de destino.'));
    }

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

    const invalidate = () => { checkbox.checked = false; };
    $$('input[name="selected_ids[]"][form="bulk-action-form"]').forEach((box) => {
      if (box.dataset.ivVisualResetReady === '1') return;
      box.dataset.ivVisualResetReady = '1';
      box.addEventListener('change', invalidate);
    });

    apply.addEventListener('click', (event) => {
      if (checkbox.checked) return;
      event.preventDefault();
      event.stopImmediatePropagation();
      checkbox.focus();
      window.alert('Confirme explicitamente a revisão visual de todas as imagens selecionadas antes da aplicação em lote.');
    }, true);
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
      blockUnverifiableSource(card, 'Publicação bloqueada: primeiro comprove a mesma foto real que foi fornecida à IA.');
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
      return;
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
      if (card) blockUnverifiableSource(card, 'Publicação bloqueada: a foto real usada na geração não foi comprovada.');
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

  const enforce = () => {
    $$('.iv-review-card').forEach(enforceCard);
    enforceBulk();
    scheduleSourceAudit();
  };
  enforce();
  const observer = new MutationObserver(enforce);
  observer.observe(document.documentElement, {childList: true, subtree: true});
  setTimeout(() => observer.disconnect(), 20000);
})();

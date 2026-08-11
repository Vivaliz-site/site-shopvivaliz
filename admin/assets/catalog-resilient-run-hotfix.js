(() => {
  'use strict';
  if (window.location.pathname !== '/admin/catalog-optimization/admin_catalog.php') return;

  async function readJson(response) {
    if (response.redirected || /\/auth\/login\.php/i.test(response.url || '')) {
      throw new Error('Sessao administrativa expirada. Entre novamente.');
    }
    const text = await response.text();
    let data;
    try { data = JSON.parse(text); } catch (_) { throw new Error(`Resposta invalida do servidor (HTTP ${response.status}).`); }
    if (!response.ok || data?.success === false) throw new Error(data?.error || `HTTP ${response.status}`);
    return data;
  }

  function writeLog(lines) {
    const log = document.getElementById('log');
    if (!log) return;
    log.style.display = 'block';
    log.textContent = lines.join('\n');
  }

  document.addEventListener('click', async (event) => {
    const run = event.target.closest('#run');
    if (!run) return;
    event.preventDefault();
    event.stopImmediatePropagation();

    const provider = document.getElementById('provider')?.value || 'openai';
    const channel = document.getElementById('channel')?.value || 'ml';
    const selected = [...document.querySelectorAll('#candidate-list input[data-candidate-id]:checked')];
    const uniqueIds = [...new Set(selected.map((node) => Number(node.value || node.dataset.candidateId || 0)).filter((id) => id > 0))];
    if (!uniqueIds.length) {
      writeLog(['Selecione ao menos um produto.']);
      return;
    }

    run.disabled = true;
    const lines = [`Gerando ${uniqueIds.length} produto(s) unico(s) para ${channel} com ${provider}...`];
    writeLog(lines);

    let ok = 0;
    let failed = 0;
    for (const productId of uniqueIds) {
      try {
        const response = await fetch('/admin/catalog-optimization/api/optimize_catalog_resilient.php', {
          method: 'POST',
          credentials: 'same-origin',
          headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
          body: JSON.stringify({ product_id: productId, target_channel: channel, provider }),
        });
        const data = await readJson(response);
        ok += 1;
        lines.push(`#${productId}: rascunho #${data.staging_id} · IA ${data.provider_used} · qualidade ${data.quality_score}/100${data.needs_review ? ' · revisar alertas' : ''}`);
        const checkbox = selected.find((node) => Number(node.value || node.dataset.candidateId || 0) === productId);
        if (checkbox) {
          checkbox.checked = false;
          checkbox.dispatchEvent(new Event('change', { bubbles: true }));
        }
      } catch (error) {
        failed += 1;
        lines.push(`#${productId}: FALHOU · ${error.message}`);
      }
      writeLog(lines);
    }

    lines.push(`Concluido: ${ok} rascunho(s), ${failed} falha(s). Nada foi publicado automaticamente.`);
    writeLog(lines);
    run.disabled = false;
  }, true);
})();

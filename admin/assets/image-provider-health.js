(() => {
  'use strict';
  if (location.pathname.replace(/\/+$/, '') !== '/admin/ai-image-studio/admin_dashboard.php') return;

  const $ = (selector, root = document) => root.querySelector(selector);
  const esc = (value) => String(value ?? '').replace(/[&<>"']/g, (char) => ({
    '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;'
  }[char]));
  const labels = {openai:'OpenAI',google:'Gemini',claude:'Claude',openrouter:'OpenRouter',groq:'Groq'};
  const EDITORS = ['openrouter', 'openai', 'google'];
  const TTL_MS = 120000;
  let cachedPayload = null;
  let checkedAt = 0;
  let inFlight = null;

  function providerViability(key) {
    const info = cachedPayload?.providers?.[key];
    if (!info) return {usable:false, warning:false, reason:'Ainda não validado.'};
    if (Number(info.working_key_count || 0) < 1) return {usable:false, warning:false, reason:info.detail || 'Nenhuma credencial autenticou.'};
    if (['claude','groq'].includes(key) && !cachedPayload?.has_visual_editor) {
      return {usable:false, warning:false, reason:'Otimizador autenticado, mas sem editor visual apto.'};
    }
    return {usable:true, warning:info.ok === false, reason:info.detail || (info.ok ? 'Disponível.' : 'Atenção operacional.')};
  }

  function recommendedEditor() {
    const selected = $('#iv-provider')?.value || '';
    if (EDITORS.includes(selected) && providerViability(selected).usable) return selected;
    return EDITORS.find((provider) => providerViability(provider).usable) || '';
  }

  async function loadHealth(force = false) {
    if (!force && cachedPayload && Date.now() - checkedAt < TTL_MS) {
      render();
      return cachedPayload;
    }
    if (inFlight) return inFlight;
    const button = $('#iv-provider-health');
    const output = $('#iv-provider-health-result');
    if (!button || !output) return null;
    button.disabled = true;
    button.textContent = 'Validando IAs...';
    output.innerHTML = '<span class="iv-chip">Consultando autenticação e capacidade recente sem gerar imagem...</span>';
    inFlight = (async () => {
      try {
        const response = await fetch('/admin/ai-image-studio/api/provider_health_check.php', {credentials:'same-origin', headers:{Accept:'application/json'}});
        if (response.redirected || /\/auth\/login\.php/i.test(response.url || '')) throw new Error('Sua sessão administrativa expirou.');
        const data = await response.json();
        if (!response.ok || data?.success === false) throw new Error(data?.error || `Falha HTTP ${response.status}.`);
        cachedPayload = data;
        checkedAt = Date.now();
        render();
        return data;
      } catch (error) {
        output.innerHTML = `<span class="iv-chip bad">Não foi possível validar as IAs: ${esc(error.message)}</span>`;
        throw error;
      } finally {
        button.disabled = false;
        button.textContent = 'Validar IAs';
        inFlight = null;
      }
    })();
    return inFlight;
  }

  function render() {
    const output = $('#iv-provider-health-result');
    const providers = cachedPayload?.providers || null;
    if (!output || !providers) return;
    const chips = Object.entries(providers).map(([key, info]) => {
      const count = Number(info.working_key_count || 0);
      const total = Number(info.key_count || 0);
      const viability = providerViability(key);
      const detail = String(viability.reason || info.detail || '');
      const klass = !viability.usable ? 'bad' : viability.warning ? 'warn' : 'good';
      const icon = !viability.usable ? '✕' : viability.warning ? '!' : '✓';
      return `<span class="iv-chip ${klass}" title="${esc(detail)}">${icon} ${esc(labels[key] || key)}${total ? ` · ${count}/${total}` : ''}</span>`;
    });
    const editor = recommendedEditor();
    if (editor) chips.push(`<span class="iv-chip good">Editor visual sugerido: ${esc(labels[editor] || editor)}</span>`);
    else chips.push('<span class="iv-chip bad">Nenhum editor visual apto no pré-flight.</span>');
    output.innerHTML = chips.join('');

    const selected = $('#iv-provider')?.value || '';
    const current = providers[selected];
    const role = $('#iv-role');
    if (role && current) {
      const base = String(role.textContent || '').replace(/\s+·\s+preflight\s+.*$/iu, '').trim();
      const viability = providerViability(selected);
      const state = viability.usable ? (viability.warning ? 'atenção: ' : 'apto: ') : 'bloqueado: ';
      role.textContent = `${base}${base ? ' · ' : ''}preflight ${state}${viability.reason}`;
    }
  }

  function install() {
    const panel = $('#iv-generation');
    if (!panel || $('#iv-provider-health')) return false;
    const toolbar = $('.iv-toolbar', panel);
    if (!toolbar) return false;
    const button = document.createElement('button');
    button.type = 'button';
    button.id = 'iv-provider-health';
    button.className = 'iv-btn';
    button.textContent = 'Validar IAs';
    const output = document.createElement('div');
    output.id = 'iv-provider-health-result';
    output.className = 'iv-summary';
    output.style.paddingTop = '0';
    toolbar.appendChild(button);
    toolbar.insertAdjacentElement('afterend', output);
    button.addEventListener('click', () => loadHealth(true).catch(() => {}));
    $('#iv-provider')?.addEventListener('change', render);
    setTimeout(() => loadHealth(false).catch(() => {}), 500);
    return true;
  }

  window.__svImageProviderHealth = {
    refresh: () => loadHealth(true),
    snapshot: () => ({checked_at: checkedAt, payload: cachedPayload}),
    viability: providerViability,
  };

  document.addEventListener('visibilitychange', () => {
    if (document.visibilityState === 'visible' && Date.now() - checkedAt >= TTL_MS) loadHealth(false).catch(() => {});
  });

  if (!install()) {
    const observer = new MutationObserver(() => {
      if (install()) observer.disconnect();
    });
    observer.observe(document.documentElement, {childList:true, subtree:true});
    setTimeout(() => observer.disconnect(), 10000);
  }
})();

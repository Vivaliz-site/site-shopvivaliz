(() => {
  'use strict';
  if (location.pathname.replace(/\/+$/, '') !== '/admin/ai-image-studio/admin_dashboard.php') return;

  const $ = (selector, root = document) => root.querySelector(selector);
  const esc = (value) => String(value ?? '').replace(/[&<>"']/g, (char) => ({
    '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;'
  }[char]));
  const labels = {openai:'OpenAI',google:'Gemini',claude:'Claude',openrouter:'OpenRouter',groq:'Groq'};
  let cached = null;

  async function loadHealth() {
    const button = $('#iv-provider-health');
    const output = $('#iv-provider-health-result');
    if (!button || !output) return;
    button.disabled = true;
    button.textContent = 'Validando IAs...';
    output.innerHTML = '<span class="iv-chip">Consultando autenticação e capacidade recente sem gerar imagem...</span>';
    try {
      const response = await fetch('/admin/ai-image-studio/api/provider_health_check.php', {credentials:'same-origin', headers:{Accept:'application/json'}});
      if (response.redirected || /\/auth\/login\.php/i.test(response.url || '')) throw new Error('Sua sessão administrativa expirou.');
      const data = await response.json();
      if (!response.ok || data?.success === false) throw new Error(data?.error || `Falha HTTP ${response.status}.`);
      cached = data.providers || {};
      render();
    } catch (error) {
      output.innerHTML = `<span class="iv-chip bad">Não foi possível validar as IAs: ${esc(error.message)}</span>`;
    } finally {
      button.disabled = false;
      button.textContent = 'Validar IAs';
    }
  }

  function render() {
    const output = $('#iv-provider-health-result');
    if (!output || !cached) return;
    output.innerHTML = Object.entries(cached).map(([key, info]) => {
      const count = Number(info.working_key_count || 0);
      const total = Number(info.key_count || 0);
      const detail = String(info.detail || (info.ok ? 'Disponível.' : 'Indisponível.'));
      return `<span class="iv-chip ${info.ok ? 'good' : 'bad'}" title="${esc(detail)}">${info.ok ? '✓' : '✕'} ${esc(labels[key] || key)}${total ? ` · ${count}/${total}` : ''}</span>`;
    }).join('') || '<span class="iv-chip warn">Nenhum provedor retornou status.</span>';
    const selected = $('#iv-provider')?.value || '';
    const current = cached[selected];
    const role = $('#iv-role');
    if (role && current) {
      const base = role.dataset.healthBase || role.textContent || '';
      role.dataset.healthBase = base;
      role.textContent = `${base}${base ? ' · ' : ''}${current.ok ? 'preflight disponível' : 'preflight indisponível: ' + current.detail}`;
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
    button.addEventListener('click', loadHealth);
    $('#iv-provider')?.addEventListener('change', render);
    return true;
  }

  if (!install()) {
    const observer = new MutationObserver(() => {
      if (install()) observer.disconnect();
    });
    observer.observe(document.documentElement, {childList:true, subtree:true});
    setTimeout(() => observer.disconnect(), 10000);
  }
})();

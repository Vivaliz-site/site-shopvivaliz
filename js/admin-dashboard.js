(function () {
  function setPill(id, text, state) {
    const node = document.getElementById(id);
    if (!node) return;
    node.textContent = text;
    node.dataset.state = state || 'idle';
    if (state === 'success') node.style.cssText = 'background:#dcfce7;color:#166534';
    if (state === 'warning') node.style.cssText = 'background:#fef3c7;color:#92400e';
    if (state === 'error') node.style.cssText = 'background:#fee2e2;color:#991b1b';
  }

  function setList(id, items) {
    const node = document.getElementById(id);
    if (!node) return;
    node.innerHTML = items.map(function (item) { return '<li>' + item + '</li>'; }).join('');
  }

  function yesNo(value) { return value ? 'OK' : 'Pendente'; }

  async function fetchJson(url) {
    const response = await fetch(url, { cache: 'no-store' });
    const text = await response.text();
    try { return { ok: response.ok, status: response.status, json: JSON.parse(text) }; }
    catch (error) { return { ok: response.ok, status: response.status, json: null }; }
  }

  async function loadHealth() {
    const settled = await Promise.allSettled([
      fetchJson('/api/health.php'),
      fetchJson('/installer/update-applied-check.php'),
      fetchJson('/installer/auto-routines.php?expected=200&limit=50')
    ]);

    const health = settled[0].status === 'fulfilled' ? settled[0].value.json : null;
    const update = settled[1].status === 'fulfilled' ? settled[1].value.json : null;
    const routines = settled[2].status === 'fulfilled' ? settled[2].value.json : null;

    if (health || update || routines) {
      const ok = Boolean((health && health.ok) || (update && update.ok) || (routines && routines.ok));
      setPill('health-status-pill', ok ? 'Operacional' : 'Atenção', ok ? 'success' : 'warning');
      setList('health-status-list', [
        'API: ' + ((health && health.status) || 'attention'),
        'Versão ativa: ' + ((update && update.version) || 'n/d'),
        'PHP: ' + ((health && health.php && health.php.version) || 'n/d'),
        'Disco usado: ' + ((health && health.disk && health.disk.used_percent) || 'n/d') + '%',
        'Produto com CEP: ' + yesNo(update && update.checks && update.checks['Produto com campo CEP']),
        'Checkout com PIX: ' + yesNo(update && update.checks && update.checks['Checkout com PIX']),
        'Checkout com boleto: ' + yesNo(update && update.checks && update.checks['Checkout com boleto']),
        'Catálogo público ativo: ' + yesNo(update && update.checks && update.checks['Catalogo publico ativo'])
      ]);
    } else {
      setPill('health-status-pill', 'Falhou', 'error');
      setList('health-status-list', ['Não foi possível carregar os checks agora.']);
    }

    if (routines && routines.olist_sync) {
      setPill('olist-status-pill', routines.olist_sync.ok ? 'Integrado' : 'Atenção', routines.olist_sync.ok ? 'success' : 'warning');
      setList('olist-status-list', [
        'Sync operacional: ' + yesNo(routines.checks && routines.checks['Olist/Tiny sincronizacao automatica sem erro operacional']),
        'Produtos validados: ' + String((routines.olist_sync && routines.olist_sync.after_count) || 0),
        'Offline access: ' + yesNo(routines.checks && routines.checks['OAuth Olist solicita offline_access']),
        'Prompt consent: ' + yesNo(routines.checks && routines.checks['OAuth Olist solicita prompt consent'])
      ]);
    } else if (routines) {
      setPill('olist-status-pill', 'Atenção', 'warning');
      setList('olist-status-list', ['Rotina carregou, mas não retornou dados de sync Olist/Tiny.']);
    } else {
      setPill('olist-status-pill', 'Falhou', 'error');
      setList('olist-status-list', ['Não foi possível validar a integração agora.']);
    }
  }

  loadHealth().catch(function () {
    setPill('health-status-pill', 'Falhou', 'error');
    setPill('olist-status-pill', 'Falhou', 'error');
    setList('health-status-list', ['Não foi possível carregar os checks agora.']);
    setList('olist-status-list', ['Não foi possível validar a integração agora.']);
  });
})();

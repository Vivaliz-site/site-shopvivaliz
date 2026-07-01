(function () {
  function setPill(id, text, state) {
    const node = document.getElementById(id);
    if (!node) return;
    node.textContent = text;
    node.dataset.state = state || 'idle';
  }

  function setList(id, items) {
    const node = document.getElementById(id);
    if (!node) return;
    node.innerHTML = items.map(function (item) {
      return '<li>' + item + '</li>';
    }).join('');
  }

  function yesNo(value) {
    return value ? 'OK' : 'Pendente';
  }

  async function loadHealth() {
    try {
      const [healthRes, updateRes, routinesRes] = await Promise.all([
        fetch('/api/health.php', { cache: 'no-store' }),
        fetch('/installer/update-applied-check.php', { cache: 'no-store' }),
        fetch('/installer/auto-routines.php?expected=200&limit=50', { cache: 'no-store' })
      ]);

      const health = await healthRes.json();
      const update = await updateRes.json();
      const routines = await routinesRes.json();

      setPill('health-status-pill', routines.ok ? 'Operacional' : 'Atenção', routines.ok ? 'success' : 'warning');
      setList('health-status-list', [
        'API: ' + (health.status || 'desconhecido'),
        'Versão ativa: ' + (update.version || 'n/d'),
        'Produto com CEP: ' + yesNo(update.checks && update.checks['Produto com campo CEP']),
        'Checkout com PIX/boleto: ' + yesNo(update.checks && update.checks['Checkout com PIX'] && update.checks['Checkout com boleto']),
        'Catálogo público ativo: ' + yesNo(update.checks && update.checks['Catalogo publico ativo']),
        'Melhor Envio: ' + yesNo(routines.checks && routines.checks['Melhor Envio pronto para cotacao']),
        'Pagar.me: ' + yesNo(routines.checks && routines.checks['Pagar.me pronto para autenticacao'])
      ]);

      setPill('olist-status-pill', routines.olist_sync && routines.olist_sync.ok ? 'Integrado' : 'Atenção', routines.olist_sync && routines.olist_sync.ok ? 'success' : 'warning');
      setList('olist-status-list', [
        'Sync operacional: ' + yesNo(routines.checks && routines.checks['Olist/Tiny sincronizacao automatica sem erro operacional']),
        'Produtos validados: ' + String((routines.olist_sync && routines.olist_sync.after_count) || 0),
        'Offline access: ' + yesNo(routines.checks && routines.checks['OAuth Olist solicita offline_access']),
        'Prompt consent: ' + yesNo(routines.checks && routines.checks['OAuth Olist solicita prompt consent']),
        'Paginação ativa: ' + (((routines.olist_sync && routines.olist_sync.query_modes_tried) || []).join(', ') || 'n/d')
      ]);
    } catch (error) {
      setPill('health-status-pill', 'Falhou', 'error');
      setPill('olist-status-pill', 'Falhou', 'error');
      setList('health-status-list', ['Não foi possível carregar os checks agora.']);
      setList('olist-status-list', ['Não foi possível validar a integração agora.']);
    }
  }

  loadHealth();
})();

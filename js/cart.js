(function () {
  const key = 'shopvivaliz_cart';
  const itemsEl = document.getElementById('cart-items');
  const totalEl = document.getElementById('cart-total');
  const checkoutLink = document.getElementById('checkout-link');
  const form = document.getElementById('checkout-form');
  const statusEl = document.getElementById('checkout-status');

  function esc(value) {
    return String(value || '').replace(/[&<>"']/g, function (char) {
      return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' })[char];
    });
  }

  function cart() {
    try {
      const parsed = JSON.parse(localStorage.getItem(key) || '[]');
      return Array.isArray(parsed) ? parsed : [];
    } catch (error) {
      return [];
    }
  }

  function save(items) {
    localStorage.setItem(key, JSON.stringify(normalizeItems(items)));
  }

  function digits(value) {
    return String(value || '').replace(/\D+/g, '');
  }

  function normalizeItems(items) {
    return (Array.isArray(items) ? items : []).map(function (item) {
      const sku = String(item && item.sku ? item.sku : '').trim();
      const name = String(item && item.name ? item.name : sku).trim();
      const quantity = Math.max(1, Math.min(99, parseInt(item && item.quantity ? item.quantity : 1, 10) || 1));
      if (!sku || !name) return null;
      return {
        sku: sku,
        name: name,
        image_url: String(item && item.image_url ? item.image_url : ''),
        price: Math.max(0, Number(item.price || 0)),
        quantity: quantity,
        olist_product_id: String(item.olist_product_id || '').trim()
      };
    }).filter(Boolean);
  }

  function setStatus(message, type) {
    if (!statusEl) return;
    statusEl.textContent = message;
    statusEl.dataset.state = type || '';
  }

  function money(value) {
    const number = Number(value || 0);
    if (!number) return 'Consulte o valor';
    return number.toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' });
  }

  function render() {
    const items = normalizeItems(cart());
    if (items.length !== cart().length) save(items);
    if (!itemsEl) return;
    if (!items.length) {
      itemsEl.innerHTML = '<div class="empty-cart">Seu carrinho está vazio.</div>';
      if (checkoutLink) checkoutLink.classList.add('disabled');
      if (totalEl) totalEl.textContent = 'Consulte o valor';
      return;
    }
    if (checkoutLink) checkoutLink.classList.remove('disabled');
    itemsEl.innerHTML = items.map(function (item, index) {
      return `
        <div class="cart-item">
          <img src="${esc(item.image_url || '/images/logo-vivaliz-square.png')}" alt="${esc(item.name)}" onerror="this.src='/images/logo-vivaliz-square.png'">
          <div>
            <strong>${esc(item.name)}</strong>
            <span>${esc(item.sku)} · ${money(item.price)}</span>
          </div>
          <input type="number" min="1" value="${Number(item.quantity || 1)}" data-qty="${index}" aria-label="Quantidade">
          <button type="button" data-remove="${index}">Remover</button>
        </div>`;
    }).join('');
    const total = items.reduce(function (sum, item) {
      return sum + Number(item.price || 0) * Number(item.quantity || 1);
    }, 0);
    if (totalEl) totalEl.textContent = money(total);
    itemsEl.querySelectorAll('[data-qty]').forEach(function (input) {
      input.addEventListener('change', function () {
        const current = cart();
        const index = Number(input.getAttribute('data-qty'));
        if (current[index]) current[index].quantity = Math.max(1, Math.min(99, parseInt(input.value || '1', 10) || 1));
        save(current);
        render();
      });
    });
    itemsEl.querySelectorAll('[data-remove]').forEach(function (button) {
      button.addEventListener('click', function () {
        const current = cart();
        current.splice(Number(button.getAttribute('data-remove')), 1);
        save(current);
        render();
      });
    });
  }

  async function submitOrder(payload) {
    const endpoints = ['/api/orders/create.php', '/api/orders/', '/pedido-criar.php'];
    let lastError = null;
    for (const endpoint of endpoints) {
      try {
        const response = await fetch(endpoint, {
          method: 'POST',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify(payload)
        });
        const data = await response.json();
        if (!response.ok || !data.ok) {
          lastError = new Error(data.error || 'order_failed');
          continue;
        }
        return data;
      } catch (error) {
        lastError = error;
      }
    }
    throw lastError || new Error('order_failed');
  }

  if (form) {
    if (window.AutoDev && typeof window.AutoDev.track === 'function') {
      window.AutoDev.track('checkout_start', { path: window.location.pathname, items_count: normalizeItems(cart()).length });
    }
    const cepInput = form.querySelector('[name="cep"]');
    if (cepInput) {
      cepInput.addEventListener('input', function () {
        const value = digits(cepInput.value).slice(0, 8);
        cepInput.value = value.length > 5 ? value.slice(0, 5) + '-' + value.slice(5) : value;
      });
    }

    form.addEventListener('submit', async function (event) {
      event.preventDefault();
      const items = normalizeItems(cart());
      if (!items.length) {
        setStatus('Adicione produtos ao carrinho antes de finalizar.', 'error');
        return;
      }
      const payload = Object.fromEntries(new FormData(form).entries());
      payload.cep = digits(payload.cep);
      if (payload.cep.length !== 8) {
        setStatus('Informe um CEP válido com 8 dígitos.', 'error');
        return;
      }
      payload.items = items;
      if (window.AutoDev && typeof window.AutoDev.track === 'function') {
        window.AutoDev.track('checkout_submit', { path: window.location.pathname, items_count: items.length, cep: payload.cep });
      }
      const submitButton = form.querySelector('[type="submit"]');
      if (submitButton) submitButton.disabled = true;
      setStatus('Enviando pedido...', 'loading');
      try {
        const data = await submitOrder(payload);
        localStorage.removeItem(key);
        setStatus(`Pedido ${data.order_number} registrado. Entraremos em contato para confirmar frete e pagamento.`, 'success');
        form.reset();
        render();
      } catch (error) {
        setStatus('Não foi possível registrar o pedido agora. Confira os dados e tente novamente.', 'error');
      } finally {
        if (submitButton) submitButton.disabled = false;
      }
    });
  }

  if (checkoutLink) {
    checkoutLink.addEventListener('click', function (event) {
      if (!normalizeItems(cart()).length) event.preventDefault();
    });
  }

  render();
})();

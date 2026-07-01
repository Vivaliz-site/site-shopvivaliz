(function () {
  const grid = document.getElementById('product-grid');
  const status = document.getElementById('catalog-status');
  const form = document.querySelector('.catalog-search');
  const input = document.getElementById('catalog-search');

  function esc(value) {
    return String(value || '').replace(/[&<>"']/g, function (char) {
      return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' })[char];
    });
  }

  function money(value) {
    const number = Number(value || 0);
    if (!number) return 'Preço sob consulta';
    return number.toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' });
  }

  function setCount(value) {
    const counter = document.getElementById('products-count');
    if (counter) counter.textContent = String(value);
  }

  function card(product) {
    const image = product.image_url || '/favicon.ico';
    const sku = product.sku || product.olist_product_id || 'sem-sku';
    const images = Number(product.images_count || 0);
    const payload = {
      sku: sku,
      name: product.name || sku,
      image_url: image,
      price: Number(product.price || 0),
      olist_product_id: product.olist_product_id || ''
    };
    const encoded = encodeURIComponent(JSON.stringify(payload));
    const productUrl = '/produto?sku=' + encodeURIComponent(payload.sku)
      + '&name=' + encodeURIComponent(payload.name)
      + '&image=' + encodeURIComponent(payload.image_url)
      + '&price=' + encodeURIComponent(String(payload.price))
      + '&olist_product_id=' + encodeURIComponent(payload.olist_product_id);
    return `
      <article class="product-card">
        <a class="product-image" href="${esc(image)}" target="_blank" rel="noreferrer">
          <img src="${esc(image)}" alt="${esc(product.name)}" loading="lazy" onerror="this.src='/favicon.ico'">
        </a>
        <div class="product-info">
          <div class="product-sku">${esc(sku)}</div>
          <h2>${esc(product.name)}</h2>
          <div class="product-meta">
            <span>${esc(money(product.price))}</span>
            <span>${images} imagem${images === 1 ? '' : 's'}</span>
          </div>
          <div class="card-actions">
            <a class="btn btn-secondary card-link" href="${esc(productUrl)}">Ver detalhes</a>
            <button class="buy-button" type="button" data-product="${encoded}">Comprar agora</button>
          </div>
        </div>
      </article>`;
  }

  async function loadCatalog(query) {
    if (!grid || !status) return;
    status.textContent = 'Carregando catálogo...';
    grid.innerHTML = '';
    const url = '/api/catalog/products.php?limit=200' + (query ? '&q=' + encodeURIComponent(query) : '');
    try {
      const response = await fetch(url, { cache: 'no-store' });
      const data = await response.json();
      if (!response.ok || data.ok === false) throw new Error(data.error || 'catalog_error');
      const products = Array.isArray(data.products) ? data.products : [];
      if (!products.length) {
        status.textContent = query ? 'Nenhum produto encontrado para a busca.' : 'Nenhum produto encontrado no catálogo.';
        setCount(0);
        return;
      }
      status.textContent = `${products.length} produto${products.length === 1 ? '' : 's'} carregado${products.length === 1 ? '' : 's'} de ${data.source || 'catálogo'}.`;
      setCount(products.length);
      grid.innerHTML = products.map(card).join('');
      grid.querySelectorAll('[data-product]').forEach(function (button) {
        button.addEventListener('click', function () {
          const product = JSON.parse(decodeURIComponent(button.getAttribute('data-product') || '{}'));
          if (window.AutoDev && typeof window.AutoDev.track === 'function') {
            window.AutoDev.track('add_to_cart', {
              sku: product.sku,
              olist_product_id: product.olist_product_id || '',
              source: window.location.pathname
            });
          }
          const items = JSON.parse(localStorage.getItem('shopvivaliz_cart') || '[]');
          const existing = items.find(function (item) { return item.sku === product.sku; });
          if (existing) existing.quantity += 1;
          else items.push(Object.assign(product, { quantity: 1 }));
          localStorage.setItem('shopvivaliz_cart', JSON.stringify(items));
          window.location.href = '/carrinho.php';
        });
      });
    } catch (error) {
      status.textContent = 'Não foi possível carregar o catálogo agora.';
      setCount(0);
    }
  }

  if (form) {
    form.addEventListener('submit', function (event) {
      event.preventDefault();
      loadCatalog(input ? input.value.trim() : '');
    });
  }

  if (input) {
    let searchTimer;
    input.addEventListener('input', function () {
      clearTimeout(searchTimer);
      searchTimer = setTimeout(function () {
        if (window.AutoDev && typeof window.AutoDev.track === 'function' && input.value.trim().length >= 2) {
          window.AutoDev.track('search', { query: input.value.trim(), path: window.location.pathname });
        }
        loadCatalog(input.value.trim());
      }, 250);
    });
  }

  loadCatalog('');
})();

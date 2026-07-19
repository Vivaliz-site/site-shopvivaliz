(function () {
  const catalogPage = document.querySelector('.catalog-page');
  const grid = document.getElementById('product-grid');
  const status = document.getElementById('catalog-status');
  const form = document.querySelector('.catalog-search');
  const input = document.getElementById('catalog-search');
  const params = new URLSearchParams(window.location.search);
  const initialCategory = String(params.get('categoria') || params.get('category') || '').trim();

  function esc(value) {
    return String(value || '').replace(/[&<>"']/g, function (char) {
      return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' })[char];
    });
  }

  function money(value) {
    const number = Number(value || 0);
    if (!number) return 'Consulte o valor';
    return number.toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' });
  }

  function setCount(value) {
    const counter = document.getElementById('products-count');
    if (counter) counter.textContent = String(value);
  }

  function customerStatus(query, category) {
    const term = String(query || '').trim();
    const activeCategory = String(category || '').trim();
    if (term && activeCategory) return `Resultados para “${term}” em ${activeCategory}`;
    if (term) return `Resultados para “${term}”`;
    if (activeCategory) return `Confira as opções em ${activeCategory}`;
    return 'Escolha seus produtos e compre com segurança';
  }

  function readCart() {
    try {
      const value = JSON.parse(localStorage.getItem('shopvivaliz_cart') || '[]');
      return Array.isArray(value) ? value : [];
    } catch (error) {
      return [];
    }
  }

  function updateCartBadge(items) {
    const badge = document.getElementById('nav-cart-count');
    if (!badge) return;
    const count = items.reduce(function (total, item) {
      return total + Number(item.quantity || 1);
    }, 0);
    badge.textContent = count > 0 ? String(count) : '';
    if (count > 0) {
      badge.classList.remove('badge-pulse');
      void badge.offsetWidth;
      badge.classList.add('badge-pulse');
    }
  }

  function addToCart(product) {
    const items = readCart();
    const existing = items.find(function (item) { return item.sku === product.sku; });
    if (existing) existing.quantity = Number(existing.quantity || 1) + 1;
    else items.push(Object.assign({}, product, { quantity: 1 }));
    localStorage.setItem('shopvivaliz_cart', JSON.stringify(items));
    updateCartBadge(items);
  }

  function bindBuyButtons(scope) {
    (scope || document).querySelectorAll('[data-product]').forEach(function (button) {
      if (button.dataset.bound === '1') return;
      button.dataset.bound = '1';
      button.addEventListener('click', function () {
        try {
          const product = JSON.parse(decodeURIComponent(button.getAttribute('data-product') || '{}'));
          fetch('/api/catalog/signal.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ event: 'cart_add', sku: product.sku, olist_product_id: product.olist_product_id || '' })
          }).catch(function () {});

          const originalText = button.innerHTML;
          button.innerHTML = '✓ Adicionado ao carrinho';
          button.classList.add('btn-success-added');

          addToCart(product);

          setTimeout(function () {
            button.innerHTML = originalText;
            button.classList.remove('btn-success-added');
          }, 1500);

          if (window.openMiniCart) window.openMiniCart();
          else window.location.href = '/carrinho';
        } catch (error) {}
      });
    });
  }

  function slugify(name, sku) {
    const base = String(name || '')
      .normalize('NFD').replace(/[̀-ͯ]/g, '')
      .toLowerCase()
      .replace(/[^a-z0-9]+/g, '-')
      .replace(/^-+|-+$/g, '')
      .slice(0, 60);
    const skuPart = String(sku || '').toLowerCase().replace(/[^a-z0-9]+/g, '');
    return (base + '-' + skuPart).replace(/^-+|-+$/g, '') || skuPart;
  }

  function card(product) {
    const image = product.image_url || '/images/logo-vivaliz-square.png';
    const sku = product.sku || product.olist_product_id || 'sem-sku';
    const category = String(product.category || '').trim();
    const slug = String(product.slug || '').trim() || (product.name && sku ? slugify(product.name, sku) : '');
    const payload = {
      sku: sku,
      name: product.name || sku,
      image_url: image,
      price: Number(product.price || 0),
      olist_product_id: product.olist_product_id || ''
    };
    const encoded = encodeURIComponent(JSON.stringify(payload));
    const hasPrice = payload.price > 0;
    const productUrl = slug
      ? '/produto/' + encodeURIComponent(slug)
      : '/produto?sku=' + encodeURIComponent(payload.sku)
        + '&name=' + encodeURIComponent(payload.name)
        + '&image=' + encodeURIComponent(payload.image_url)
        + '&price=' + encodeURIComponent(String(payload.price))
        + '&olist_product_id=' + encodeURIComponent(payload.olist_product_id);
    const contactUrl = '/contato?sku=' + encodeURIComponent(payload.sku)
      + '&produto=' + encodeURIComponent(payload.name);
    return `
      <article class="product-card">
        <a class="product-image" href="${esc(productUrl)}">
          <img src="${esc(image)}" alt="${esc(product.name)}" loading="lazy" onerror="this.src='/images/logo-vivaliz-square.png'">
        </a>
        <div class="product-info">
          ${category ? `<div class="product-category">${esc(category)}</div>` : ''}
          <h2>${esc(product.name)}</h2>
          <div class="product-price">${esc(money(product.price))}</div>
          <div class="card-actions">
            <a class="btn btn-secondary card-link" href="${esc(productUrl)}">Ver detalhes</a>
            ${hasPrice
              ? `<button class="buy-button" type="button" data-product="${encoded}">Comprar agora</button>`
              : `<a class="btn btn-primary card-link" href="${esc(contactUrl)}">Falar com vendas</a>`}
          </div>
        </div>
      </article>`;
  }

  // Paginacao client-side para qualquer grid que use este script -- antes,
  // este loadCatalog() buscava ate 200 produtos e jogava tudo de uma vez no
  // grid via JS, o que inclusive sobrescrevia a paginacao server-side de
  // 20/pagina do /catalogo publico assim que a pagina carregava.
  const GRID_PAGE_SIZE = 20;
  let gridPage = 1;
  let gridProducts = [];

  function gridPagerEl() {
    if (!grid) return null;
    let pager = document.getElementById('catalog-grid-pager');
    if (!pager) {
      pager = document.createElement('div');
      pager.id = 'catalog-grid-pager';
      pager.style.cssText = 'display:flex; align-items:center; justify-content:center; gap:12px; padding:16px 0;';
      grid.insertAdjacentElement('afterend', pager);
    }
    return pager;
  }

  function renderGridPage(page) {
    const totalPages = Math.max(1, Math.ceil(gridProducts.length / GRID_PAGE_SIZE));
    gridPage = Math.max(1, Math.min(totalPages, page));
    const start = (gridPage - 1) * GRID_PAGE_SIZE;
    const pageItems = gridProducts.slice(start, start + GRID_PAGE_SIZE);
    grid.innerHTML = pageItems.map(card).join('');
    bindBuyButtons(grid);

    const pager = gridPagerEl();
    if (pager) {
      pager.innerHTML = `
        <button class="btn btn-secondary" type="button" id="grid-pager-prev" ${gridPage <= 1 ? 'disabled' : ''}>&laquo; Anterior</button>
        <span class="muted">Página ${gridPage} de ${totalPages}</span>
        <button class="btn btn-secondary" type="button" id="grid-pager-next" ${gridPage >= totalPages ? 'disabled' : ''}>Próxima &raquo;</button>
      `;
      const prevBtn = document.getElementById('grid-pager-prev');
      const nextBtn = document.getElementById('grid-pager-next');
      if (prevBtn) prevBtn.addEventListener('click', function () { renderGridPage(gridPage - 1); window.scrollTo({ top: grid.offsetTop - 80, behavior: 'smooth' }); });
      if (nextBtn) nextBtn.addEventListener('click', function () { renderGridPage(gridPage + 1); window.scrollTo({ top: grid.offsetTop - 80, behavior: 'smooth' }); });
    }
  }

  async function loadCatalog(query, category) {
    if (!grid || !status) return;
    const activeCategory = String(category || '').trim();
    status.textContent = 'Preparando as melhores opções para você...';

    // A paginacao renderizada pelo PHP (ex: catalogo.php) fica redundante
    // assim que este script assume o grid com paginacao propria.
    var serverPagination = document.querySelector('.catalog-pagination');
    if (serverPagination) serverPagination.hidden = true;

    grid.innerHTML = `
      <div class="product-card sv-skeleton-card" style="box-shadow:none; border:1px solid #e2e8f0; opacity:0.8;">
        <div class="sv-skeleton sv-skeleton-image" style="height: 180px; width: 100%; border-radius: 8px; margin-bottom: 12px;"></div>
        <div class="product-info" style="padding: 12px 0 0 0;">
          <div class="sv-skeleton sv-skeleton-title" style="width: 35%; height: 12px; margin-bottom: 8px;"></div>
          <div class="sv-skeleton sv-skeleton-title" style="width: 85%; height: 16px; margin-bottom: 12px;"></div>
          <div class="sv-skeleton sv-skeleton-price" style="width: 40%; height: 20px; margin-bottom: 12px;"></div>
          <div style="display: flex; gap: 8px; margin-top: 10px;">
            <div class="sv-skeleton sv-skeleton-btn" style="flex: 1; height: 32px;"></div>
            <div class="sv-skeleton sv-skeleton-btn" style="flex: 1; height: 32px;"></div>
          </div>
        </div>
      </div>
    `.repeat(6);
    const url = '/api/catalog/products.php?limit=200'
      + (query ? '&q=' + encodeURIComponent(query) : '')
      + (activeCategory ? '&category=' + encodeURIComponent(activeCategory) : '');
    try {
      const response = await fetch(url, { cache: 'no-store' });
      const data = await response.json();
      if (!response.ok || data.ok === false) throw new Error(data.error || 'catalog_error');
      const products = Array.isArray(data.products) ? data.products : [];
      if (!products.length) {
        status.textContent = query
          ? 'Não encontramos esse produto. Tente outro nome ou explore as categorias.'
          : 'Novos produtos estarão disponíveis em breve.';
        grid.innerHTML = '';
        const pager = document.getElementById('catalog-grid-pager');
        if (pager) pager.innerHTML = '';
        setCount(0);
        return;
      }
      status.textContent = customerStatus(query, activeCategory);
      setCount(products.length);
      gridProducts = products;
      renderGridPage(1);
    } catch (error) {
      status.textContent = 'Não conseguimos exibir os produtos agora. Tente novamente em instantes.';
      grid.innerHTML = '';
      setCount(0);
    }
  }

  updateCartBadge(readCart());
  bindBuyButtons(document);

  if (!catalogPage || !grid || !status) return;

  status.textContent = customerStatus(input ? input.value.trim() : '', initialCategory);

  if (form) {
    form.addEventListener('submit', function (event) {
      event.preventDefault();
      loadCatalog(input ? input.value.trim() : '', initialCategory);
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
        loadCatalog(input.value.trim(), initialCategory);
      }, 250);
    });
  }

  loadCatalog(input ? input.value.trim() : '', initialCategory);
})();
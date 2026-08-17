(function () {
  const catalogPage = document.querySelector('.catalog-page');
  const grid = document.getElementById('product-grid');
  const status = document.getElementById('catalog-status');
  const form = document.querySelector('.catalog-search');
  const input = document.getElementById('catalog-search');
  if (!catalogPage || !grid || !status || !form || !input) return;

  const params = new URLSearchParams(window.location.search);
  const tools = document.querySelector('.catalog-tools');
  const filterNav = tools ? tools.querySelector('.category-filters') : null;
  const categorySelect = tools ? tools.querySelector('#catalog-category-select') : null;
  const preservePath = Boolean(
    document.body &&
    document.body.dataset &&
    document.body.dataset.catalogPreservePath === '1'
  );
  const initialCategory = String(params.get('categoria') || params.get('category') || '').trim();
  const initialSort = String(params.get('ordem') || params.get('sort') || 'relevance').trim() || 'relevance';
  let activeCategory = initialCategory;
  let activeSort = initialSort;
  let searchTimer;

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

  function buildCatalogUrl(query, category, sort, page) {
    const next = new URLSearchParams();
    const term = String(query || '').trim();
    const currentCategory = String(category || '').trim();
    const currentSort = String(sort || 'relevance').trim() || 'relevance';
    const currentPage = Number(page || 1);
    if (term) next.set('q', term);
    if (currentCategory) next.set('categoria', currentCategory);
    if (currentSort && currentSort !== 'relevance') next.set('ordem', currentSort);
    if (currentPage > 1) next.set('page', String(currentPage));
    const search = next.toString();
    return '/catalogo' + (search ? '?' + search : '');
  }

  function syncPageState(query, category, sort, page, replace) {
    if (preservePath) return;
    const url = buildCatalogUrl(query, category, sort, page);
    const titleParts = ['Catálogo | Vivaliz'];
    if (query) titleParts.unshift('Busca: ' + query);
    if (category) titleParts.unshift(category);
    document.title = titleParts.join(' • ');
    window.history[replace ? 'replaceState' : 'pushState'](
      { q: query, categoria: category, ordem: sort, page: page || 1 },
      '',
      url
    );
  }

  function setActiveFilter(category) {
    if (categorySelect) categorySelect.value = String(category || '').trim();
    if (!filterNav) return;
    filterNav.querySelectorAll('.cat-filter').forEach(function (link) {
      const linkCategory = String(link.getAttribute('data-category') || '').trim();
      const active = linkCategory === String(category || '').trim();
      link.classList.toggle('active', active);
      if (active) {
        try {
          link.scrollIntoView({ behavior: 'smooth', inline: 'center', block: 'nearest' });
        } catch (error) {}
      }
    });
  }

  function ensureToolbar() {
    if (!tools) return null;
    let toolbar = tools.querySelector('.sv-catalog-toolbar');
    if (!toolbar) {
      toolbar = document.createElement('div');
      toolbar.className = 'sv-catalog-toolbar';
      toolbar.innerHTML = '<div class="sv-catalog-toolbar-left"></div><label class="sv-sort-wrap"><span>Ordenar por</span><select aria-label="Ordenar produtos"><option value="relevance">Relevância</option><option value="price-asc">Menor preço</option><option value="price-desc">Maior preço</option><option value="name">Nome A–Z</option></select></label>';
      tools.insertBefore(toolbar, tools.firstChild);
    }
    const select = toolbar.querySelector('.sv-sort-wrap select');
    if (select) {
      select.value = activeSort;
      if (select.dataset.svBound !== '1') {
        select.dataset.svBound = '1';
        select.addEventListener('change', function () {
          activeSort = this.value || 'relevance';
          loadCatalog(input ? input.value.trim() : '', activeCategory, 1);
        });
      }
    }
    return toolbar;
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
    window.dispatchEvent(new CustomEvent('shopvivaliz:add_to_cart', {
      detail: { product_id: String(product.olist_product_id || product.sku || '') }
    }));
    updateCartBadge(items);
  }

  function decodeProductPayload(rawValue) {
    const raw = String(rawValue || '{}');
    const decoder = (typeof window !== 'undefined' && typeof window.decodeURIComponent === 'function')
      ? window.decodeURIComponent.bind(window)
      : function (value) { return value; };
    return JSON.parse(decoder(raw));
  }

  function bindBuyButtons(scope) {
    (scope || document).querySelectorAll('[data-product]').forEach(function (button) {
      if (button.dataset.bound === '1') return;
      button.dataset.bound = '1';
      button.addEventListener('click', function () {
        try {
          const product = decodeProductPayload(button.getAttribute('data-product'));
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
    const image = product.image_url || '/images/logo-vivaliz-square-v2.png';
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
    const images = Array.isArray(product.images) ? product.images.slice(0, 10).filter(Boolean) : [image];
    const imagesJson = encodeURIComponent(JSON.stringify(images));
    const productIdAttr = product.olist_product_id || sku;
    return `
      <article class="product-card" data-sku="${esc(sku)}" data-product-id="${esc(productIdAttr)}">
        <a class="product-image" href="${esc(productUrl)}" data-images="${imagesJson}" data-sku="${esc(sku)}" data-product-id="${esc(productIdAttr)}">
          <img src="${esc(image)}" alt="${esc(product.name)}" loading="lazy" onerror="this.src='/images/logo-vivaliz-square-v2.png'">
        </a>
        <div class="product-info">
          ${category ? `<div class="product-category">${esc(category)}</div>` : ''}
          <h2>${esc(product.name)}</h2>
          <div class="product-price">${esc(money(product.price))}</div>
          <div class="card-actions">
            <a class="btn btn-secondary card-link" href="${esc(productUrl)}" data-sku="${esc(sku)}" data-product-id="${esc(productIdAttr)}">Ver detalhes</a>
            ${hasPrice
              ? `<button class="buy-button" type="button" data-product="${encoded}" data-sku="${esc(sku)}" data-product-id="${esc(productIdAttr)}" data-add-to-cart="1">Comprar agora</button>`
              : `<a class="btn btn-primary card-link" href="${esc(contactUrl)}" data-sku="${esc(sku)}" data-product-id="${esc(productIdAttr)}">Falar com vendas</a>`}
          </div>
        </div>
      </article>`;
  }

  // Paginacao client-side para qualquer grid que use este script -- antes,
  // este loadCatalog() buscava ate 200 produtos e jogava tudo de uma vez no
  // grid via JS. Agora a paginação e a ordenação principais são servidas pela API.
  const GRID_PAGE_SIZE = 20;
  let gridPage = 1;
  let gridTotalPages = 1;
  let gridTotalProducts = 0;

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
    gridPage = Math.max(1, Math.min(gridTotalPages, page));
    setActiveFilter(activeCategory);
    syncPageState(input ? input.value.trim() : '', activeCategory, activeSort, gridPage, gridPage === 1);

    const pager = gridPagerEl();
    if (pager) {
      pager.innerHTML = `
        <button class="btn btn-secondary" type="button" id="grid-pager-prev" ${gridPage <= 1 ? 'disabled' : ''}>&laquo; Anterior</button>
        <span class="muted">Página ${gridPage} de ${gridTotalPages}</span>
        <button class="btn btn-secondary" type="button" id="grid-pager-next" ${gridPage >= gridTotalPages ? 'disabled' : ''}>Próxima &raquo;</button>
      `;
      const prevBtn = document.getElementById('grid-pager-prev');
      const nextBtn = document.getElementById('grid-pager-next');
      if (prevBtn) prevBtn.addEventListener('click', function () { loadCatalog(input ? input.value.trim() : '', activeCategory, gridPage - 1); window.scrollTo({ top: grid.offsetTop - 80, behavior: 'smooth' }); });
      if (nextBtn) nextBtn.addEventListener('click', function () { loadCatalog(input ? input.value.trim() : '', activeCategory, gridPage + 1); window.scrollTo({ top: grid.offsetTop - 80, behavior: 'smooth' }); });
    }
  }

  async function loadCatalog(query, category, page) {
    if (!grid || !status) return;
    activeCategory = String(category || '').trim();
    syncPageState(query, activeCategory, activeSort, Number(page || 1), Number(page || 1) === 1);
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
    const url = '/api/catalog/products.php?limit=' + GRID_PAGE_SIZE
      + '&page=' + encodeURIComponent(String(Number(page || 1)))
      + '&ordem=' + encodeURIComponent(activeSort)
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
        gridTotalPages = 1;
        gridTotalProducts = 0;
        return;
      }
      status.textContent = customerStatus(query, activeCategory);
      setCount(Number(data.total || products.length));
      gridTotalProducts = Number(data.total || products.length);
      gridTotalPages = Math.max(1, Number(data.total_pages || 1));
      grid.innerHTML = products.map(card).join('');
      bindBuyButtons(grid);
      renderGridPage(Number(page || 1));
    } catch (error) {
      status.textContent = 'Não conseguimos exibir os produtos agora. Tente novamente em instantes.';
      grid.innerHTML = '';
      setCount(0);
      gridTotalPages = 1;
      gridTotalProducts = 0;
    }
  }

  updateCartBadge(readCart());
  bindBuyButtons(document);
  ensureToolbar();

  if (categorySelect && categorySelect.dataset.svBound !== '1') {
    categorySelect.dataset.svBound = '1';
    categorySelect.addEventListener('change', function () {
      activeCategory = String(this.value || '').trim();
      loadCatalog(input ? input.value.trim() : '', activeCategory, 1);
    });
  }

  if (!catalogPage || !grid || !status) return;

  status.textContent = customerStatus(input ? input.value.trim() : '', initialCategory);

  if (form) {
    form.addEventListener('submit', function (event) {
      event.preventDefault();
      loadCatalog(input ? input.value.trim() : '', activeCategory, 1);
    });
  }

  if (filterNav) {
    filterNav.querySelectorAll('.cat-filter').forEach(function (link) {
      link.dataset.category = String(link.dataset.category || link.getAttribute('data-category') || '').trim();
      link.addEventListener('click', function (event) {
        event.preventDefault();
        activeCategory = String(link.dataset.category || '').trim();
        loadCatalog(input ? input.value.trim() : '', activeCategory, 1);
      });
    });
  }

  if (input) {
    input.addEventListener('input', function () {
      clearTimeout(searchTimer);
      searchTimer = setTimeout(function () {
        if (window.AutoDev && typeof window.AutoDev.track === 'function' && input.value.trim().length >= 2) {
          window.AutoDev.track('search', { query: input.value.trim(), path: window.location.pathname });
        }
        loadCatalog(input.value.trim(), activeCategory);
      }, 250);
    });
  }

  if (!preservePath) {
    window.addEventListener('popstate', function () {
      const nextParams = new URLSearchParams(window.location.search);
      activeCategory = String(nextParams.get('categoria') || nextParams.get('category') || '').trim();
      activeSort = String(nextParams.get('ordem') || nextParams.get('sort') || 'relevance').trim() || 'relevance';
      if (input) input.value = String(nextParams.get('q') || '').trim();
      ensureToolbar();
      loadCatalog(input ? input.value.trim() : '', activeCategory, Number(nextParams.get('page') || 1));
    });
  }

  // O PHP ja entrega a primeira pagina, os filtros e o estado vazio. Evitar
  // substituir tudo por skeletons no carregamento inicial elimina uma segunda
  // chamada à API e o CLS causado pela expansão/remoção assíncrona do grid.
  setActiveFilter(initialCategory);
  if (initialSort !== 'relevance') {
    loadCatalog(input ? input.value.trim() : '', initialCategory, Number(params.get('page') || 1));
  }
})();

(function () {
  'use strict';

  if (window.__svCategoryCarouselV2Started) return;
  window.__svCategoryCarouselV2Started = true;
  // Impede que a versão antiga do reparador inicialize depois deste módulo.
  window.__svCategoryAliasRepairStarted = true;
  window.__svCategoryAliasRepairDone = false;

  var CATEGORY_ENDPOINT = '/api/catalog/category-images.php';
  var CATEGORY_ROTATION_INTERVAL = 3000;
  var INTERACTION_PAUSE = 10000;
  var states = [];
  var reducedMotion = typeof window.matchMedia === 'function'
    ? window.matchMedia('(prefers-reduced-motion: reduce)')
    : null;
  var pageVisible = !document.hidden;

  var aliases = {
    'rodizios & rodas': ['rodizios', 'rodas para carrinhos e moveis'],
    'ferramentas': ['ferramentas', 'ferramentas manuais', 'caixas de ferramentas'],
    'organizacao': ['organizacao', 'armarios e organizacao'],
    'jardim & floreiras': ['floreiras e jardim', 'vasos decorativos'],
    'ferragens & fixacao': ['fixacao e ferragem', 'ferragens e fixacao']
  };

  function normalize(value) {
    return String(value || '')
      .normalize('NFD')
      .replace(/[\u0300-\u036f]/g, '')
      .trim()
      .toLowerCase()
      .replace(/\s+/g, ' ');
  }

  function validImage(value) {
    var url = String(value || '').trim();
    return !!url
      && !/unsplash\.com|placeholder|default|logo-vivaliz|no[-_ ]?image|sem[-_ ]?imagem/i.test(url)
      && url.indexOf('/public/assets/category-images/') === -1
      && (/^https?:\/\//i.test(url) || url.charAt(0) === '/');
  }

  function parseImages(rawValue) {
    if (!rawValue) return [];
    var candidates = [String(rawValue)];
    try { candidates.push(decodeURIComponent(String(rawValue))); } catch (error) {}
    candidates.push(String(rawValue).replace(/&quot;/g, '"').replace(/&#039;/g, "'").replace(/&amp;/g, '&'));

    for (var index = 0; index < candidates.length; index += 1) {
      try {
        var parsed = JSON.parse(candidates[index]);
        if (Array.isArray(parsed)) return parsed;
      } catch (error) {}
    }
    return [];
  }

  function normalizeItem(value) {
    if (typeof value === 'string') return { src: value, name: '', sku: '' };
    if (!value || typeof value !== 'object') return null;
    return {
      src: value.src || value.url || value.image_url || '',
      name: value.name || value.product_name || '',
      sku: value.sku || value.id || ''
    };
  }

  function uniqueItems(values) {
    var seen = Object.create(null);
    var result = [];
    (values || []).forEach(function (value) {
      var item = normalizeItem(value);
      if (!item) return;
      item.src = String(item.src || '').trim();
      item.name = String(item.name || '').trim();
      item.sku = String(item.sku || '').trim();
      if (!validImage(item.src) || seen[item.src]) return;
      seen[item.src] = true;
      result.push(item);
    });
    return result.slice(0, 8);
  }

  function injectStyles() {
    if (document.getElementById('sv-category-rotation-v2-style')) return;
    var style = document.createElement('style');
    style.id = 'sv-category-rotation-v2-style';
    style.textContent =
      '.category-slide-image-wrapper{position:relative;isolation:isolate}' +
      '.category-slide-image-wrapper .category-slide-img{transition:opacity .28s ease,transform .28s ease}' +
      '.category-slide-image-wrapper.is-changing .category-slide-img{opacity:.28;transform:scale(.985)}' +
      '.category-slide-image-wrapper[data-sv-category-rotation="ready"]::after{content:"";position:absolute;right:8px;bottom:8px;width:7px;height:7px;border-radius:999px;background:rgba(16,185,129,.88);box-shadow:0 0 0 3px rgba(255,255,255,.78);pointer-events:none}' +
      '@media(prefers-reduced-motion:reduce){.category-slide-image-wrapper .category-slide-img{transition:none}.category-slide-image-wrapper[data-sv-category-rotation="ready"]::after{display:none}}';
    document.head.appendChild(style);
  }

  function inlineItems(wrapper, image) {
    var values = parseImages(wrapper.getAttribute('data-images'));
    var current = image.currentSrc || image.getAttribute('src') || '';
    if (validImage(current)) values.unshift(current);

    // O carrossel genérico antigo também procura `data-images`. Remover o
    // atributo evita dois timers disputando o mesmo card em versões em cache.
    wrapper.removeAttribute('data-images');
    wrapper.dataset.svCategoryCarouselClaimed = 'v2';
    return uniqueItems(values);
  }

  function createState(card, order) {
    var wrapper = card.querySelector('.category-slide-image-wrapper');
    var image = wrapper && wrapper.querySelector('img.category-slide-img');
    var title = card.querySelector('strong');
    if (!wrapper || !(image instanceof HTMLImageElement) || !title) return null;

    var state = {
      card: card,
      wrapper: wrapper,
      image: image,
      label: String(title.textContent || '').trim(),
      order: order,
      items: inlineItems(wrapper, image),
      position: 0,
      visible: typeof IntersectionObserver !== 'function',
      interacting: false,
      pauseTimer: null,
      rotationTimer: null,
      failed: Object.create(null),
      destroyed: false
    };

    // O texto do link já informa categoria e quantidade. A foto que muda é
    // decorativa e não deve alterar o nome acessível a cada três segundos.
    image.alt = '';
    image.setAttribute('aria-hidden', 'true');
    wrapper.setAttribute('aria-hidden', 'true');
    image.loading = 'lazy';
    image.decoding = 'async';
    return state;
  }

  function canRotate(state) {
    return !state.destroyed
      && state.items.length > 1
      && state.visible
      && pageVisible
      && !state.interacting
      && !(reducedMotion && reducedMotion.matches);
  }

  function stopTimer(state) {
    if (state.rotationTimer) window.clearTimeout(state.rotationTimer);
    state.rotationTimer = null;
  }

  function preloadNext(state) {
    if (state.items.length < 2) return;
    for (var offset = 1; offset <= state.items.length; offset += 1) {
      var candidate = state.items[(state.position + offset) % state.items.length];
      if (!candidate || state.failed[candidate.src]) continue;
      var preloader = new Image();
      preloader.decoding = 'async';
      preloader.src = candidate.src;
      return;
    }
  }

  function showItem(state, requestedIndex, attempts) {
    if (!state.items.length || attempts >= state.items.length || state.destroyed) return;
    var index = (requestedIndex + state.items.length) % state.items.length;
    var item = state.items[index];
    if (!item || state.failed[item.src]) {
      showItem(state, index + 1, attempts + 1);
      return;
    }

    var current = state.image.currentSrc || state.image.getAttribute('src') || '';
    if (current === item.src) {
      state.position = index;
      state.image.dataset.svCategorySource = 'catalog-rotation';
      state.image.dataset.svProductSku = item.sku;
      state.image.dataset.svProductName = item.name;
      preloadNext(state);
      return;
    }

    var loader = new Image();
    loader.decoding = 'async';
    loader.onload = function () {
      if (state.destroyed) return;
      state.wrapper.classList.add('is-changing');
      state.position = index;
      state.image.src = item.src;
      state.image.dataset.svCategorySource = 'catalog-rotation';
      state.image.dataset.svProductSku = item.sku;
      state.image.dataset.svProductName = item.name;
      state.card.classList.add('has-real-image');
      window.setTimeout(function () {
        state.wrapper.classList.remove('is-changing');
      }, 180);
      preloadNext(state);
    };
    loader.onerror = function () {
      state.failed[item.src] = true;
      showItem(state, index + 1, attempts + 1);
    };
    loader.src = item.src;
  }

  function advance(state) {
    showItem(state, state.position + 1, 0);
  }

  function schedule(state, delay) {
    stopTimer(state);
    if (!canRotate(state)) return;
    state.rotationTimer = window.setTimeout(function tick() {
      advance(state);
      if (!canRotate(state)) {
        stopTimer(state);
        return;
      }
      state.rotationTimer = window.setTimeout(tick, CATEGORY_ROTATION_INTERVAL);
    }, Math.max(100, Number(delay) || CATEGORY_ROTATION_INTERVAL));
  }

  function sync(state, initial) {
    state.wrapper.dataset.svCategoryRotation = state.items.length > 1 ? 'ready' : 'single';
    if (initial && state.items.length) showItem(state, 0, 0);
    var stagger = initial ? (CATEGORY_ROTATION_INTERVAL + ((state.order % 8) * 140)) : CATEGORY_ROTATION_INTERVAL;
    schedule(state, stagger);
  }

  function pauseFor(state, duration) {
    state.interacting = true;
    stopTimer(state);
    if (state.pauseTimer) window.clearTimeout(state.pauseTimer);
    state.pauseTimer = window.setTimeout(function () {
      state.interacting = false;
      sync(state, false);
    }, duration);
  }

  function bindInteraction(state) {
    state.card.addEventListener('mouseenter', function () {
      state.interacting = true;
      stopTimer(state);
    });
    state.card.addEventListener('mouseleave', function () {
      state.interacting = false;
      sync(state, false);
    });
    state.card.addEventListener('focusin', function () {
      state.interacting = true;
      stopTimer(state);
    });
    state.card.addEventListener('focusout', function () {
      state.interacting = false;
      sync(state, false);
    });
    state.card.addEventListener('touchstart', function () {
      pauseFor(state, INTERACTION_PAUSE);
    }, { passive: true });
    state.image.addEventListener('error', function () {
      var failedUrl = state.image.currentSrc || state.image.getAttribute('src') || '';
      if (failedUrl) state.failed[failedUrl] = true;
      advance(state);
    });
  }

  function acceptedCategories(label) {
    var key = normalize(label);
    return aliases[key] || [key];
  }

  function categoryItems(label, categories) {
    var accepted = acceptedCategories(label);
    var result = [];
    (categories || []).forEach(function (category) {
      var categoryName = normalize(category && (category.name || category.category));
      if (accepted.indexOf(categoryName) === -1) return;
      if (Array.isArray(category.items)) result = result.concat(category.items);
      else if (Array.isArray(category.images)) result = result.concat(category.images);
      else if (category.image_url) result.push({
        src: category.image_url,
        name: category.product_name || '',
        sku: category.sku || ''
      });
    });
    return uniqueItems(result);
  }

  function applyPayload(payload) {
    var categories = payload && Array.isArray(payload.categories) ? payload.categories : [];
    states.forEach(function (state) {
      var catalogItems = categoryItems(state.label, categories);
      var merged = uniqueItems(catalogItems.concat(state.items));
      if (merged.length) state.items = merged;
      state.failed = Object.create(null);
      sync(state, true);
    });
    window.__svCategoryAliasRepairDone = true;
    window.__svCategoryRotationReady = true;
  }

  function initialize() {
    var cards = Array.prototype.slice.call(document.querySelectorAll('.home-categories .category-slide'));
    if (!cards.length) {
      window.__svCategoryAliasRepairDone = true;
      return;
    }

    injectStyles();
    cards.forEach(function (card, index) {
      var state = createState(card, index);
      if (!state) return;
      states.push(state);
      bindInteraction(state);
    });

    var observer = typeof IntersectionObserver === 'function'
      ? new IntersectionObserver(function (entries) {
        entries.forEach(function (entry) {
          var state = states.find(function (item) { return item.wrapper === entry.target; });
          if (!state) return;
          state.visible = entry.isIntersecting;
          sync(state, false);
        });
      }, { threshold: 0.08, rootMargin: '120px 0px' })
      : null;

    if (observer) states.forEach(function (state) { observer.observe(state.wrapper); });

    fetch(CATEGORY_ENDPOINT, {
      headers: { Accept: 'application/json' },
      credentials: 'same-origin',
      cache: 'no-cache'
    }).then(function (response) {
      if (!response.ok) throw new Error('category_images_request_failed');
      return response.json();
    }).then(function (payload) {
      applyPayload(payload && payload.ok !== false ? payload : {});
    }).catch(function () {
      // O HTML já contém uma lista de imagens. Em falha de rede, ainda usamos
      // somente as fotos disponíveis no documento, sem bloquear a home.
      applyPayload({ categories: [] });
    });

    document.addEventListener('visibilitychange', function () {
      pageVisible = !document.hidden;
      states.forEach(function (state) { sync(state, false); });
    });

    if (reducedMotion) {
      var onMotionChange = function () {
        states.forEach(function (state) { sync(state, false); });
      };
      if (typeof reducedMotion.addEventListener === 'function') reducedMotion.addEventListener('change', onMotionChange);
      else if (typeof reducedMotion.addListener === 'function') reducedMotion.addListener(onMotionChange);
    }

    window.addEventListener('pagehide', function () {
      states.forEach(function (state) {
        state.destroyed = true;
        stopTimer(state);
        if (state.pauseTimer) window.clearTimeout(state.pauseTimer);
      });
      if (observer) observer.disconnect();
    }, { once: true });
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', initialize, { once: true });
  else initialize();
}());

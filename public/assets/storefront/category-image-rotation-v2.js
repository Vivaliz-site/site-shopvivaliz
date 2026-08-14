(function () {
  'use strict';

  if (window.__svCategoryRotationV2Started) return;
  window.__svCategoryRotationV2Started = true;
  // Mantem compatibilidade e impede o reparador antigo de disputar os cards.
  window.__svCategoryAliasRepairStarted = true;

  var CATEGORY_ENDPOINT = '/api/catalog/category-images.php';
  var INTERVAL = 3000;
  var INTERACTION_PAUSE = 10000;
  var states = [];
  var pageVisible = !document.hidden;
  var reduceMotion = typeof window.matchMedia === 'function'
    ? window.matchMedia('(prefers-reduced-motion: reduce)')
    : null;

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

  function isRealImage(value) {
    var url = String(value || '').trim();
    return !!url
      && (/^https?:\/\//i.test(url) || url.charAt(0) === '/')
      && !/unsplash\.com|placeholder|default|logo-vivaliz|no[-_ ]?image|sem[-_ ]?imagem/i.test(url)
      && url.indexOf('/public/assets/category-images/') === -1;
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
      if (!isRealImage(item.src) || seen[item.src]) return;
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
      '.category-slide-image-wrapper.is-changing .category-slide-img{opacity:.24;transform:scale(.985)}' +
      '.sv-category-rotation-status{position:absolute;right:8px;bottom:8px;z-index:3;display:inline-flex;align-items:center;gap:5px;padding:4px 6px;border-radius:999px;background:rgba(7,27,47,.82);color:#fff;font-size:10px;font-weight:800;line-height:1;box-shadow:0 4px 14px rgba(7,27,47,.16);backdrop-filter:blur(5px)}' +
      '.sv-category-rotation-status[hidden]{display:none!important}' +
      '.sv-category-rotation-toggle{display:inline-flex;align-items:center;justify-content:center;width:26px;height:26px;margin:0;padding:0;border:1px solid rgba(255,255,255,.45);border-radius:999px;background:rgba(255,255,255,.15);color:#fff;font:inherit;font-size:12px;cursor:pointer;touch-action:manipulation}' +
      '.sv-category-rotation-toggle:focus-visible{outline:3px solid rgba(45,187,87,.35);outline-offset:2px}' +
      '@media(max-width:760px){.sv-category-rotation-status{right:7px;bottom:7px}.sv-category-rotation-toggle{width:30px;height:30px}}' +
      '@media(prefers-reduced-motion:reduce){.category-slide-image-wrapper .category-slide-img{transition:none}.sv-category-rotation-status{display:none!important}}';
    document.head.appendChild(style);
  }

  function createState(card, order) {
    var wrapper = card.querySelector('.category-slide-image-wrapper');
    var image = wrapper && wrapper.querySelector('img.category-slide-img');
    var title = card.querySelector('strong');
    if (!wrapper || !(image instanceof HTMLImageElement) || !title) return null;

    var inline = parseImages(wrapper.getAttribute('data-images'));
    var current = image.currentSrc || image.getAttribute('src') || '';
    if (isRealImage(current)) inline.unshift(current);

    // O carrossel genérico legado também procura este atributo. Retirá-lo antes
    // de o script antigo carregar garante apenas um timer por card.
    wrapper.removeAttribute('data-images');
    wrapper.dataset.svCategoryCarouselClaimed = 'v2';

    image.alt = '';
    image.setAttribute('aria-hidden', 'true');
    image.loading = 'lazy';
    image.decoding = 'async';
    wrapper.setAttribute('aria-hidden', 'true');

    var status = document.createElement('span');
    status.className = 'sv-category-rotation-status';
    status.hidden = true;
    status.setAttribute('aria-hidden', 'true');
    var counter = document.createElement('span');
    counter.className = 'sv-category-rotation-counter';
    counter.textContent = '1 / 1';
    var toggle = document.createElement('button');
    toggle.type = 'button';
    toggle.className = 'sv-category-rotation-toggle';
    toggle.textContent = '❚❚';
    toggle.setAttribute('aria-label', 'Pausar troca automática de fotos');
    status.append(counter, toggle);
    wrapper.appendChild(status);

    return {
      card: card,
      wrapper: wrapper,
      image: image,
      label: String(title.textContent || '').trim(),
      order: order,
      items: uniqueItems(inline),
      position: 0,
      status: status,
      counter: counter,
      toggle: toggle,
      visible: typeof IntersectionObserver !== 'function',
      manualPaused: false,
      interacting: false,
      rotationTimer: null,
      resumeTimer: null,
      failed: Object.create(null),
      destroyed: false
    };
  }

  function updateControls(state) {
    var multiple = state.items.length > 1;
    state.status.hidden = !multiple;
    state.wrapper.dataset.svCategoryRotation = multiple ? 'ready' : 'single';
    state.counter.textContent = (Math.min(state.position + 1, Math.max(1, state.items.length))) + ' / ' + Math.max(1, state.items.length);
    state.toggle.textContent = state.manualPaused ? '▶' : '❚❚';
    state.toggle.setAttribute('aria-label', state.manualPaused
      ? 'Retomar troca automática de fotos'
      : 'Pausar troca automática de fotos');
    state.toggle.setAttribute('aria-pressed', state.manualPaused ? 'true' : 'false');
  }

  function canRotate(state) {
    return !state.destroyed
      && state.items.length > 1
      && state.visible
      && pageVisible
      && !state.manualPaused
      && !state.interacting
      && !(reduceMotion && reduceMotion.matches);
  }

  function clearSchedule(state) {
    if (state.rotationTimer) window.clearTimeout(state.rotationTimer);
    state.rotationTimer = null;
  }

  function preloadNext(state) {
    if (state.items.length < 2) return;
    for (var offset = 1; offset <= state.items.length; offset += 1) {
      var next = state.items[(state.position + offset) % state.items.length];
      if (!next || state.failed[next.src]) continue;
      var preloader = new Image();
      preloader.decoding = 'async';
      preloader.src = next.src;
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
      updateControls(state);
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
      updateControls(state);
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
    clearSchedule(state);
    updateControls(state);
    if (!canRotate(state)) return;
    state.rotationTimer = window.setTimeout(function tick() {
      advance(state);
      if (!canRotate(state)) {
        clearSchedule(state);
        return;
      }
      state.rotationTimer = window.setTimeout(tick, INTERVAL);
    }, Math.max(100, Number(delay) || INTERVAL));
  }

  function sync(state, initial) {
    if (initial && state.items.length) showItem(state, 0, 0);
    var stagger = initial ? (INTERVAL + ((state.order % 8) * 140)) : INTERVAL;
    schedule(state, stagger);
  }

  function pauseForInteraction(state, duration) {
    state.interacting = true;
    clearSchedule(state);
    if (state.resumeTimer) window.clearTimeout(state.resumeTimer);
    state.resumeTimer = window.setTimeout(function () {
      state.interacting = false;
      sync(state, false);
    }, duration);
  }

  function bindState(state) {
    state.toggle.addEventListener('click', function (event) {
      event.preventDefault();
      event.stopPropagation();
      state.manualPaused = !state.manualPaused;
      sync(state, false);
    });
    state.card.addEventListener('mouseenter', function () {
      state.interacting = true;
      clearSchedule(state);
    });
    state.card.addEventListener('mouseleave', function () {
      state.interacting = false;
      sync(state, false);
    });
    state.card.addEventListener('focusin', function (event) {
      if (event.target === state.toggle) return;
      state.interacting = true;
      clearSchedule(state);
    });
    state.card.addEventListener('focusout', function () {
      state.interacting = false;
      sync(state, false);
    });
    state.card.addEventListener('touchstart', function (event) {
      if (event.target === state.toggle) return;
      pauseForInteraction(state, INTERACTION_PAUSE);
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

  function itemsForCategory(label, categories) {
    var accepted = acceptedCategories(label);
    var values = [];
    (categories || []).forEach(function (category) {
      var categoryName = normalize(category && (category.name || category.category));
      if (accepted.indexOf(categoryName) === -1) return;
      if (Array.isArray(category.items)) values = values.concat(category.items);
      else if (Array.isArray(category.images)) values = values.concat(category.images);
      else if (category.image_url) values.push({
        src: category.image_url,
        name: category.product_name || '',
        sku: category.sku || ''
      });
    });
    return uniqueItems(values);
  }

  function applyPayload(payload) {
    var categories = payload && Array.isArray(payload.categories) ? payload.categories : [];
    states.forEach(function (state) {
      var catalogItems = itemsForCategory(state.label, categories);
      var merged = uniqueItems(catalogItems.concat(state.items));
      if (merged.length) state.items = merged;
      state.failed = Object.create(null);
      sync(state, true);
    });
    window.__svCategoryAliasRepairDone = true;
    window.__svCategoryRotationV2Ready = true;
  }

  function initialize() {
    var cards = Array.prototype.slice.call(document.querySelectorAll('.home-categories .category-slide'));
    if (!cards.length) {
      window.__svCategoryRotationV2Ready = true;
      window.__svCategoryAliasRepairDone = true;
      return;
    }

    injectStyles();
    cards.forEach(function (card, index) {
      var state = createState(card, index);
      if (!state) return;
      states.push(state);
      bindState(state);
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
      // A home continua funcional com os dados embutidos se a API estiver fora.
      applyPayload({ categories: [] });
    });

    document.addEventListener('visibilitychange', function () {
      pageVisible = !document.hidden;
      states.forEach(function (state) { sync(state, false); });
    });

    if (reduceMotion) {
      var onMotionChange = function () {
        states.forEach(function (state) { sync(state, false); });
      };
      if (typeof reduceMotion.addEventListener === 'function') reduceMotion.addEventListener('change', onMotionChange);
      else if (typeof reduceMotion.addListener === 'function') reduceMotion.addListener(onMotionChange);
    }

    window.addEventListener('pagehide', function () {
      states.forEach(function (state) {
        state.destroyed = true;
        clearSchedule(state);
        if (state.resumeTimer) window.clearTimeout(state.resumeTimer);
      });
      if (observer) observer.disconnect();
    }, { once: true });
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', initialize, { once: true });
  else initialize();
}());

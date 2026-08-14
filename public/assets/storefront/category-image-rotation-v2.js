/**
 * Category image rotation v2.
 *
 * Claims only category cards before the legacy generic carousel starts, then
 * rotates the real product images every three seconds with preloading,
 * visibility awareness, bounded error recovery and a global pause control.
 */
(function () {
  'use strict';

  var SOURCE_ATTR = 'data-sv-category-images';
  var LEGACY_ATTR = 'data-images';
  var SELECTOR = '.category-slide-image-wrapper[' + LEGACY_ATTR + '], .category-slide-image-wrapper[' + SOURCE_ATTR + ']';
  var INTERVAL_MS = 3000;
  var INTERACTION_PAUSE_MS = 9000;
  var STORAGE_KEY = 'svCategoryRotationPausedV2';
  var controllers = [];
  var observer = null;
  var globallyPaused = false;
  var documentVisible = !document.hidden;
  var reducedMotion = Boolean(window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches);
  var styleInstalled = false;

  function safeStorageGet(key) {
    try { return window.localStorage.getItem(key); } catch (error) { return null; }
  }

  function safeStorageSet(key, value) {
    try { window.localStorage.setItem(key, value); } catch (error) {}
  }

  globallyPaused = reducedMotion || safeStorageGet(STORAGE_KEY) === '1';

  function decodeCandidates(rawValue) {
    var raw = String(rawValue || '').trim();
    if (!raw) return [];
    var values = [raw];
    try { values.push(decodeURIComponent(raw)); } catch (error) {}
    values.push(raw
      .replace(/&quot;/g, '"')
      .replace(/&#0?39;/g, "'")
      .replace(/&amp;/g, '&'));
    return values;
  }

  function parsePayload(rawValue) {
    var candidates = decodeCandidates(rawValue);
    for (var index = 0; index < candidates.length; index += 1) {
      try {
        var parsed = JSON.parse(candidates[index]);
        if (Array.isArray(parsed)) return parsed;
      } catch (error) {}
    }
    return [];
  }

  function normalizedUrl(value) {
    var raw = String(value || '').trim();
    if (!raw || /^javascript:/i.test(raw) || /^data:text\//i.test(raw)) return '';
    try {
      return new URL(raw, window.location.href).href;
    } catch (error) {
      return '';
    }
  }

  function weakImage(src) {
    return /(?:placeholder|sem[-_ ]?imagem|no[-_ ]?image|default[-_ ]?product|produto[-_ ]?padrao)/i.test(src);
  }

  function entryFrom(value, fallbackAlt) {
    var source = value;
    var alt = fallbackAlt;
    if (value && typeof value === 'object') {
      source = value.src || value.url || value.image || '';
      alt = String(value.alt || value.name || fallbackAlt || '').trim();
    }
    var src = normalizedUrl(source);
    if (!src) return null;
    return { src: src, alt: alt || fallbackAlt || 'Produto da categoria' };
  }

  function uniqueEntries(rawItems, currentSrc, fallbackAlt) {
    var entries = [];
    var seen = Object.create(null);
    var current = entryFrom({ src: currentSrc, alt: fallbackAlt }, fallbackAlt);
    var items = current ? [current].concat(rawItems || []) : (rawItems || []);

    items.forEach(function (value) {
      var entry = entryFrom(value, fallbackAlt);
      if (!entry || seen[entry.src]) return;
      seen[entry.src] = true;
      entries.push(entry);
    });

    var strong = entries.filter(function (entry) { return !weakImage(entry.src); });
    return strong.length >= 2 ? strong : entries;
  }

  function installStyles() {
    if (styleInstalled || document.getElementById('sv-category-rotation-v2-style')) return;
    styleInstalled = true;
    var style = document.createElement('style');
    style.id = 'sv-category-rotation-v2-style';
    style.textContent = [
      '.category-slide-image-wrapper[data-sv-rotation-ready="1"]{position:relative;isolation:isolate}',
      '.category-slide-image-wrapper[data-sv-rotation-ready="1"] img{transition:opacity .24s ease,transform .28s ease;will-change:opacity,transform}',
      '.category-slide-image-wrapper.sv-category-is-swapping img{opacity:.24;transform:scale(.985)}',
      '.sv-category-rotation-status{position:absolute;right:8px;bottom:8px;z-index:3;display:inline-flex;align-items:center;justify-content:center;min-width:38px;min-height:24px;padding:3px 7px;border:1px solid rgba(255,255,255,.58);border-radius:999px;background:rgba(8,32,55,.78);color:#fff;font-size:11px;font-weight:800;line-height:1;letter-spacing:.02em;box-shadow:0 3px 12px rgba(8,32,55,.18);pointer-events:none;backdrop-filter:blur(5px)}',
      '.sv-category-rotation-toggle{display:inline-flex;align-items:center;justify-content:center;gap:7px;min-height:44px;padding:8px 12px;border:1px solid rgba(23,59,99,.16);border-radius:999px;background:#fff;color:#173b63;font:inherit;font-size:12px;font-weight:800;line-height:1;cursor:pointer;box-shadow:0 4px 14px rgba(15,23,42,.06)}',
      '.sv-category-rotation-toggle:hover{background:#edf4fb}.sv-category-rotation-toggle:focus-visible{outline:3px solid rgba(20,184,166,.34);outline-offset:3px}',
      '.sv-category-rotation-toggle svg{width:15px;height:15px;flex:0 0 auto}',
      '.home-categories .section-heading{gap:10px}.home-categories .section-heading>.sv-category-rotation-actions{display:flex;align-items:center;gap:8px;margin-left:auto}',
      '@media(max-width:760px){.home-categories .section-heading{display:grid!important;grid-template-columns:minmax(0,1fr) auto!important;align-items:start!important}.home-categories .section-heading>.sv-category-rotation-actions{grid-column:1/-1;width:100%;margin:0;justify-content:space-between}.home-categories .section-heading>.sv-category-rotation-actions .btn{min-height:44px}.sv-category-rotation-toggle{padding:8px 10px}.sv-category-rotation-status{right:6px;bottom:6px;min-height:22px}}',
      '@media(prefers-reduced-motion:reduce){.category-slide-image-wrapper[data-sv-rotation-ready="1"] img{transition:none!important}.category-slide-image-wrapper.sv-category-is-swapping img{opacity:1;transform:none}}'
    ].join('');
    document.head.appendChild(style);
  }

  function categoryName(wrapper) {
    var card = wrapper.closest('.category-slide');
    var strong = card && card.querySelector('strong');
    var image = wrapper.querySelector('img');
    return String((strong && strong.textContent) || (image && image.alt) || 'categoria').trim();
  }

  function createStatus(wrapper, count) {
    var status = wrapper.querySelector('.sv-category-rotation-status');
    if (!status) {
      status = document.createElement('span');
      status.className = 'sv-category-rotation-status';
      status.setAttribute('aria-hidden', 'true');
      wrapper.appendChild(status);
    }
    status.textContent = '1 / ' + count;
    return status;
  }

  function preload(entry) {
    return new Promise(function (resolve, reject) {
      var probe = new Image();
      var settled = false;
      function finish(success) {
        if (settled) return;
        settled = true;
        probe.onload = null;
        probe.onerror = null;
        success ? resolve(entry) : reject(new Error('image_load_failed'));
      }
      probe.onload = function () { finish(true); };
      probe.onerror = function () { finish(false); };
      probe.decoding = 'async';
      probe.src = entry.src;
      if (probe.complete) {
        if (probe.naturalWidth > 0) finish(true);
        else window.setTimeout(function () { finish(probe.naturalWidth > 0); }, 0);
      }
    });
  }

  function createController(wrapper, entries, position) {
    var image = wrapper.querySelector('img');
    var status = createStatus(wrapper, entries.length);
    var currentIndex = Math.max(0, entries.findIndex(function (entry) {
      return normalizedUrl(image.currentSrc || image.getAttribute('src')) === entry.src;
    }));
    var timer = null;
    var firstTimer = null;
    var resumeTimer = null;
    var visible = !observer;
    var locallyPaused = false;
    var swapping = false;
    var failed = Object.create(null);
    var baseAlt = image.alt || categoryName(wrapper);

    wrapper.setAttribute('data-sv-rotation-ready', '1');
    wrapper.setAttribute('role', 'group');
    wrapper.setAttribute('aria-label', 'Fotos de produtos da categoria ' + categoryName(wrapper) + '. Alternância automática a cada 3 segundos.');
    image.setAttribute('draggable', 'false');
    status.textContent = (currentIndex + 1) + ' / ' + entries.length;

    function shouldRun() {
      return !globallyPaused && !locallyPaused && visible && documentVisible && entries.length > 1;
    }

    function stop() {
      if (timer) window.clearInterval(timer);
      if (firstTimer) window.clearTimeout(firstTimer);
      timer = null;
      firstTimer = null;
    }

    function nextUsableIndex() {
      for (var step = 1; step <= entries.length; step += 1) {
        var candidate = (currentIndex + step) % entries.length;
        if (!failed[entries[candidate].src]) return candidate;
      }
      return -1;
    }

    function swap() {
      if (swapping || !shouldRun()) return;
      var nextIndex = nextUsableIndex();
      if (nextIndex < 0 || nextIndex === currentIndex) {
        stop();
        return;
      }
      swapping = true;
      var entry = entries[nextIndex];
      preload(entry).then(function () {
        wrapper.classList.add('sv-category-is-swapping');
        window.setTimeout(function () {
          image.src = entry.src;
          image.alt = baseAlt + ' — foto ' + (nextIndex + 1) + ' de ' + entries.length;
          currentIndex = nextIndex;
          status.textContent = (currentIndex + 1) + ' / ' + entries.length;
          var completeSwap = function () {
            wrapper.classList.remove('sv-category-is-swapping');
            swapping = false;
            wrapper.dispatchEvent(new CustomEvent('shopvivaliz:category-image-change', {
              bubbles: true,
              detail: { index: currentIndex, total: entries.length }
            }));
          };
          if (typeof image.decode === 'function') image.decode().catch(function () {}).then(completeSwap);
          else window.setTimeout(completeSwap, 120);
        }, reducedMotion ? 0 : 90);
      }).catch(function () {
        failed[entry.src] = true;
        swapping = false;
        if (nextUsableIndex() < 0) stop();
      });
    }

    function sync() {
      stop();
      if (!shouldRun()) return;
      firstTimer = window.setTimeout(function () {
        swap();
        timer = window.setInterval(swap, INTERVAL_MS);
      }, INTERVAL_MS + (position * 180));
    }

    function pauseFor(duration) {
      locallyPaused = true;
      if (resumeTimer) window.clearTimeout(resumeTimer);
      sync();
      resumeTimer = window.setTimeout(function () {
        locallyPaused = false;
        sync();
      }, duration);
    }

    wrapper.addEventListener('mouseenter', function () { locallyPaused = true; sync(); });
    wrapper.addEventListener('mouseleave', function () { locallyPaused = false; sync(); });
    wrapper.addEventListener('focusin', function () { locallyPaused = true; sync(); });
    wrapper.addEventListener('focusout', function () { locallyPaused = false; sync(); });
    wrapper.addEventListener('pointerdown', function () { pauseFor(INTERACTION_PAUSE_MS); }, { passive: true });

    var controller = {
      element: wrapper,
      sync: sync,
      setVisible: function (value) { visible = Boolean(value); sync(); },
      destroy: function () {
        stop();
        if (resumeTimer) window.clearTimeout(resumeTimer);
      }
    };
    sync();
    return controller;
  }

  function updateToggle(button) {
    if (!button) return;
    var paused = globallyPaused;
    button.setAttribute('aria-pressed', paused ? 'true' : 'false');
    button.innerHTML = paused
      ? '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M8 5v14l11-7z" fill="currentColor"/></svg><span>Reproduzir fotos</span>'
      : '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M7 5h4v14H7zm6 0h4v14h-4z" fill="currentColor"/></svg><span>Pausar fotos</span>';
    button.title = paused ? 'Reativar a troca automática das fotos das categorias' : 'Pausar a troca automática das fotos das categorias';
  }

  function ensureGlobalToggle() {
    var heading = document.querySelector('.home-categories .section-heading');
    if (!heading || heading.querySelector('.sv-category-rotation-toggle')) return;
    var catalogLink = heading.querySelector('a.btn');
    var actions = document.createElement('div');
    actions.className = 'sv-category-rotation-actions';
    var button = document.createElement('button');
    button.type = 'button';
    button.className = 'sv-category-rotation-toggle';
    updateToggle(button);
    button.addEventListener('click', function () {
      globallyPaused = !globallyPaused;
      safeStorageSet(STORAGE_KEY, globallyPaused ? '1' : '0');
      updateToggle(button);
      controllers.forEach(function (controller) { controller.sync(); });
    });
    actions.appendChild(button);
    if (catalogLink) actions.appendChild(catalogLink);
    heading.appendChild(actions);
  }

  function claimAndInitialize() {
    installStyles();
    var wrappers = Array.prototype.slice.call(document.querySelectorAll(SELECTOR));
    wrappers.forEach(function (wrapper, position) {
      if (wrapper.getAttribute('data-sv-category-rotation-bound') === '1') return;
      var raw = wrapper.getAttribute(SOURCE_ATTR) || wrapper.getAttribute(LEGACY_ATTR) || '[]';
      wrapper.setAttribute(SOURCE_ATTR, raw);
      wrapper.removeAttribute(LEGACY_ATTR);
      wrapper.setAttribute('data-sv-category-rotation-bound', '1');
      var image = wrapper.querySelector('img');
      if (!image) return;
      var name = categoryName(wrapper);
      var entries = uniqueEntries(parsePayload(raw), image.currentSrc || image.getAttribute('src'), name);
      if (entries.length < 2) {
        wrapper.setAttribute('data-sv-rotation-ready', '0');
        return;
      }
      var controller = createController(wrapper, entries, position);
      controllers.push(controller);
      if (observer) observer.observe(wrapper);
    });
    if (controllers.length) ensureGlobalToggle();
  }

  if (typeof IntersectionObserver === 'function') {
    observer = new IntersectionObserver(function (entries) {
      entries.forEach(function (entry) {
        var controller = controllers.find(function (item) { return item.element === entry.target; });
        if (controller) controller.setVisible(entry.isIntersecting && entry.intersectionRatio > 0.08);
      });
    }, { threshold: [0, 0.08, 0.25] });
  }

  document.addEventListener('visibilitychange', function () {
    documentVisible = !document.hidden;
    controllers.forEach(function (controller) { controller.sync(); });
  });

  if (window.matchMedia) {
    var motionQuery = window.matchMedia('(prefers-reduced-motion: reduce)');
    var onMotionChange = function (event) {
      reducedMotion = event.matches;
      if (reducedMotion) globallyPaused = true;
      var toggle = document.querySelector('.sv-category-rotation-toggle');
      updateToggle(toggle);
      controllers.forEach(function (controller) { controller.sync(); });
    };
    if (typeof motionQuery.addEventListener === 'function') motionQuery.addEventListener('change', onMotionChange);
    else if (typeof motionQuery.addListener === 'function') motionQuery.addListener(onMotionChange);
  }

  claimAndInitialize();

  var mutationTimer = null;
  var mutationObserver = new MutationObserver(function () {
    if (mutationTimer) window.clearTimeout(mutationTimer);
    mutationTimer = window.setTimeout(claimAndInitialize, 80);
  });
  mutationObserver.observe(document.documentElement, { childList: true, subtree: true });

  window.addEventListener('beforeunload', function () {
    controllers.forEach(function (controller) { controller.destroy(); });
    if (observer) observer.disconnect();
    mutationObserver.disconnect();
  });

  window.__svCategoryRotationV2 = {
    interval: INTERVAL_MS,
    pause: function () { globallyPaused = true; controllers.forEach(function (controller) { controller.sync(); }); },
    play: function () { globallyPaused = false; controllers.forEach(function (controller) { controller.sync(); }); },
    count: function () { return controllers.length; }
  };
})();
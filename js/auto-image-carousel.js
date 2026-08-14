/**
 * Rotação automática das imagens de produto e categorias.
 *
 * Regras importantes:
 * - categorias usam somente fotos reais de produtos presentes em data-images;
 * - a troca ocorre a cada 3 segundos quando o card está visível;
 * - timers pausam fora da viewport, em aba oculta e durante interação;
 * - imagens quebradas são puladas sem travar o carrossel;
 * - pessoas que preferem movimento reduzido não recebem rotação automática.
 */
(function () {
  'use strict';

  var ROTATION_INTERVAL = 3000;
  var INTERACTION_PAUSE = 10000;
  var reducedMotion = Boolean(window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches);
  var documentVisible = !document.hidden;
  var carousels = new Map();

  var visibilityObserver = typeof IntersectionObserver === 'function'
    ? new IntersectionObserver(function (entries) {
      entries.forEach(function (entry) {
        var carousel = carousels.get(entry.target);
        if (!carousel) return;
        carousel.visible = entry.isIntersecting && entry.intersectionRatio > 0;
        carousel.sync();
      });
    }, { rootMargin: '80px 0px', threshold: 0.05 })
    : null;

  function unique(values) {
    return values.filter(function (value, index, all) { return value && all.indexOf(value) === index; });
  }

  function parseImages(rawValue) {
    if (!rawValue) return [];
    var candidates = [rawValue];
    try { candidates.push(decodeURIComponent(rawValue)); } catch (error) {}
    candidates.push(rawValue.replace(/&quot;/g, '"').replace(/&#039;/g, "'").replace(/&amp;/g, '&'));

    for (var index = 0; index < candidates.length; index += 1) {
      try {
        var parsed = JSON.parse(candidates[index]);
        if (Array.isArray(parsed)) {
          return unique(parsed.map(function (value) { return String(value || '').trim(); }).filter(Boolean));
        }
      } catch (error) {}
    }
    return [];
  }

  function isRealProductImage(url) {
    var value = String(url || '').trim();
    if (!value) return false;
    if (/\/public\/assets\/category-images\//i.test(value)) return false;
    if (/placeholder|logo-vivaliz|no[-_ ]?image|sem[-_ ]?imagem|unsplash\.com/i.test(value)) return false;
    return /^https?:\/\//i.test(value) || /^\/uploads\//i.test(value);
  }

  function preload(url) {
    return new Promise(function (resolve, reject) {
      var candidate = new Image();
      candidate.decoding = 'async';
      candidate.onload = function () { resolve(url); };
      candidate.onerror = reject;
      candidate.src = url;
    });
  }

  function injectGalleryStyles() {
    if (document.getElementById('sv-product-gallery-navigation-styles')) return;
    var style = document.createElement('style');
    style.id = 'sv-product-gallery-navigation-styles';
    style.textContent = '#product-zoom-box.sv-product-gallery-enabled{position:relative}' +
      '.sv-product-gallery-arrow{position:absolute;top:50%;z-index:6;display:inline-flex;align-items:center;justify-content:center;width:48px;height:48px;padding:0;border:1px solid rgba(15,23,42,.16);border-radius:999px;background:rgba(255,255,255,.94);color:#173b63;box-shadow:0 8px 24px rgba(15,23,42,.18);cursor:pointer;transform:translateY(-50%)}' +
      '.sv-product-gallery-arrow:focus-visible{outline:3px solid rgba(11,79,136,.34);outline-offset:3px}.sv-product-gallery-arrow--previous{left:14px}.sv-product-gallery-arrow--next{right:14px}.sv-product-gallery-arrow svg{width:24px;height:24px;pointer-events:none}' +
      '.sv-product-gallery-counter{position:absolute;right:14px;bottom:14px;z-index:6;min-width:54px;padding:5px 10px;border-radius:999px;background:rgba(15,23,42,.78);color:#fff;font-size:.78rem;font-weight:700;line-height:1.2;text-align:center;pointer-events:none}' +
      '.sv-auto-carousel-image{transition:opacity .24s ease,transform .24s ease}.sv-auto-carousel-image.sv-switching{opacity:.18!important;transform:scale(.985)}' +
      '@media(max-width:640px){.sv-product-gallery-arrow{width:44px;height:44px}.sv-product-gallery-arrow--previous{left:8px}.sv-product-gallery-arrow--next{right:8px}.sv-product-gallery-counter{right:8px;bottom:8px}}' +
      '@media(prefers-reduced-motion:reduce){.sv-auto-carousel-image{transition:none!important}}';
    document.head.appendChild(style);
  }

  function createArrow(direction, label) {
    var button = document.createElement('button');
    button.type = 'button';
    button.className = 'sv-product-gallery-arrow sv-product-gallery-arrow--' + direction;
    button.setAttribute('aria-label', label);
    button.innerHTML = direction === 'previous'
      ? '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M15 18l-6-6 6-6" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"/></svg>'
      : '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M9 18l6-6-6-6" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"/></svg>';
    return button;
  }

  function createCarousel(element, advance) {
    var carousel = {
      element: element,
      timer: null,
      resumeTimer: null,
      paused: false,
      visible: !visibilityObserver,
      sync: function () {
        var shouldRun = !reducedMotion && documentVisible && !this.paused && this.visible;
        if (!shouldRun) {
          if (this.timer) clearInterval(this.timer);
          this.timer = null;
          return;
        }
        if (!this.timer) this.timer = setInterval(advance, ROTATION_INTERVAL);
      },
      pause: function () { this.paused = true; this.sync(); },
      resume: function () { this.paused = false; this.sync(); },
      pauseFor: function (duration) {
        var self = this;
        this.pause();
        if (this.resumeTimer) clearTimeout(this.resumeTimer);
        this.resumeTimer = setTimeout(function () { self.resume(); }, duration);
      },
      destroy: function () {
        if (this.timer) clearInterval(this.timer);
        if (this.resumeTimer) clearTimeout(this.resumeTimer);
        this.timer = null;
      }
    };
    carousels.set(element, carousel);
    if (visibilityObserver) visibilityObserver.observe(element);
    carousel.sync();
    return carousel;
  }

  function pauseOnInteraction(element, carousel) {
    element.addEventListener('mouseenter', function () { carousel.pause(); });
    element.addEventListener('mouseleave', function () { carousel.resume(); });
    element.addEventListener('focusin', function () { carousel.pause(); });
    element.addEventListener('focusout', function () { carousel.resume(); });
    element.addEventListener('touchstart', function () { carousel.pauseFor(INTERACTION_PAUSE); }, { passive: true });
  }

  function initProductGallery() {
    var gallery = document.getElementById('product-zoom-box');
    var thumbnails = Array.prototype.slice.call(document.querySelectorAll('.product-gallery-thumbnails .thumb-btn'));
    if (!gallery || thumbnails.length < 2 || carousels.has(gallery)) return;

    var currentIndex = Math.max(0, thumbnails.findIndex(function (button) { return button.classList.contains('active'); }));
    var advancing = false;
    var touchStartX = null;
    injectGalleryStyles();
    gallery.classList.add('sv-product-gallery-enabled');
    gallery.setAttribute('role', 'region');
    gallery.setAttribute('aria-label', 'Galeria de imagens do produto');
    if (!gallery.hasAttribute('tabindex')) gallery.setAttribute('tabindex', '0');
    var previous = createArrow('previous', 'Ver imagem anterior');
    var next = createArrow('next', 'Ver próxima imagem');
    var counter = document.createElement('span');
    counter.className = 'sv-product-gallery-counter';
    counter.setAttribute('aria-hidden', 'true');
    gallery.append(previous, next, counter);

    function updateControls() {
      counter.textContent = (currentIndex + 1) + ' / ' + thumbnails.length;
      previous.setAttribute('aria-label', 'Ver imagem anterior. Imagem atual ' + (currentIndex + 1) + ' de ' + thumbnails.length);
      next.setAttribute('aria-label', 'Ver próxima imagem. Imagem atual ' + (currentIndex + 1) + ' de ' + thumbnails.length);
    }
    function select(index) {
      advancing = true;
      currentIndex = (index + thumbnails.length) % thumbnails.length;
      thumbnails[currentIndex].click();
      advancing = false;
      updateControls();
    }
    var carousel = createCarousel(gallery, function () { select(currentIndex + 1); });
    thumbnails.forEach(function (button, index) {
      button.addEventListener('click', function () {
        currentIndex = index;
        if (!advancing) carousel.pauseFor(INTERACTION_PAUSE);
        updateControls();
      });
    });
    previous.addEventListener('click', function (event) { event.preventDefault(); event.stopPropagation(); select(currentIndex - 1); carousel.pauseFor(INTERACTION_PAUSE); });
    next.addEventListener('click', function (event) { event.preventDefault(); event.stopPropagation(); select(currentIndex + 1); carousel.pauseFor(INTERACTION_PAUSE); });
    gallery.addEventListener('keydown', function (event) {
      if (event.key === 'ArrowLeft') { event.preventDefault(); select(currentIndex - 1); carousel.pauseFor(INTERACTION_PAUSE); }
      if (event.key === 'ArrowRight') { event.preventDefault(); select(currentIndex + 1); carousel.pauseFor(INTERACTION_PAUSE); }
    });
    gallery.addEventListener('touchstart', function (event) { if (event.touches.length === 1) touchStartX = event.touches[0].clientX; }, { passive: true });
    gallery.addEventListener('touchend', function (event) {
      if (touchStartX === null || event.changedTouches.length !== 1) return;
      var distance = event.changedTouches[0].clientX - touchStartX;
      touchStartX = null;
      if (Math.abs(distance) < 48) return;
      select(currentIndex + (distance < 0 ? 1 : -1));
      carousel.pauseFor(INTERACTION_PAUSE);
    }, { passive: true });
    pauseOnInteraction(gallery, carousel);
    updateControls();
  }

  function initProductCardCarousels() {
    injectGalleryStyles();
    document.querySelectorAll('.product-image[data-images], .category-slide-image-wrapper[data-images]').forEach(function (element) {
      if (carousels.has(element)) return;
      var image = element.querySelector('img');
      if (!image) return;

      var isCategory = element.classList.contains('category-slide-image-wrapper');
      var images = parseImages(element.getAttribute('data-images'));
      if (isCategory) images = images.filter(isRealProductImage);

      var currentSrc = String(image.getAttribute('src') || image.currentSrc || '').trim();
      if (!isCategory && currentSrc && images.indexOf(currentSrc) === -1) images.unshift(currentSrc);
      images = unique(images);
      if (images.length < 2) return;

      image.classList.add('sv-auto-carousel-image');
      image.dataset.svCarousel = isCategory ? 'category-products' : 'product';
      element.dataset.svCarouselInterval = String(ROTATION_INTERVAL);

      var currentIndex = isCategory ? -1 : Math.max(0, images.indexOf(currentSrc));
      var switching = false;

      function show(index, attempt) {
        if (switching || !images.length) return;
        var tries = Number(attempt || 0);
        if (tries >= images.length) return;
        var nextIndex = (index + images.length) % images.length;
        var nextUrl = images[nextIndex];
        switching = true;
        preload(nextUrl).then(function () {
          image.classList.add('sv-switching');
          window.setTimeout(function () {
            currentIndex = nextIndex;
            image.src = nextUrl;
            image.dataset.svCarouselIndex = String(currentIndex);
            image.dataset.svCarouselSource = 'data-images';
            window.requestAnimationFrame(function () {
              window.requestAnimationFrame(function () {
                image.classList.remove('sv-switching');
                switching = false;
              });
            });
          }, reducedMotion ? 0 : 90);
        }).catch(function () {
          switching = false;
          show(nextIndex + 1, tries + 1);
        });
      }

      var carousel = createCarousel(element, function () { show(currentIndex + 1, 0); });
      image.addEventListener('error', function () { switching = false; show(currentIndex + 1, 0); });
      pauseOnInteraction(element, carousel);
    });
  }

  function initAll() {
    initProductGallery();
    initProductCardCarousels();
  }

  document.addEventListener('visibilitychange', function () {
    documentVisible = !document.hidden;
    carousels.forEach(function (carousel) { carousel.sync(); });
  });

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', initAll, { once: true });
  else initAll();

  var observer = new MutationObserver(function () { window.setTimeout(initProductCardCarousels, 60); });
  observer.observe(document.body, { childList: true, subtree: true });

  window.addEventListener('beforeunload', function () {
    carousels.forEach(function (carousel) { carousel.destroy(); });
    observer.disconnect();
    if (visibilityObserver) visibilityObserver.disconnect();
  });
})();

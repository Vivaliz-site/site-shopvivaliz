/**
 * Rotação automática das imagens de produto.
 *
 * Alterna a galeria da página do produto e as imagens dos cards a cada três
 * segundos. A rotação pausa enquanto o cliente interage com o conteúdo e só
 * mantém timers para elementos visíveis.
 */
(function () {
  'use strict';

  var ROTATION_INTERVAL = 3000;
  var carousels = new Map();
  var visibilityObserver = typeof IntersectionObserver === 'function'
    ? new IntersectionObserver(function (entries) {
      entries.forEach(function (entry) {
        var carousel = carousels.get(entry.target);
        if (!carousel) return;
        carousel.visible = entry.isIntersecting;
        carousel.sync();
      });
    }, { threshold: 0.1 })
    : null;

  function parseImages(rawValue) {
    if (!rawValue) return [];

    var candidates = [rawValue];
    try { candidates.push(decodeURIComponent(rawValue)); } catch (error) {}
    candidates.push(rawValue.replace(/&quot;/g, '"').replace(/&#039;/g, "'").replace(/&amp;/g, '&'));

    for (var index = 0; index < candidates.length; index += 1) {
      try {
        var parsed = JSON.parse(candidates[index]);
        if (Array.isArray(parsed)) {
          return parsed.map(function (value) { return String(value || '').trim(); }).filter(Boolean);
        }
      } catch (error) {}
    }

    return [];
  }

  function injectGalleryStyles() {
    if (document.getElementById('sv-product-gallery-navigation-styles')) return;
    var style = document.createElement('style');
    style.id = 'sv-product-gallery-navigation-styles';
    style.textContent = '#product-zoom-box.sv-product-gallery-enabled{position:relative}' +
      '.sv-product-gallery-arrow{position:absolute;top:50%;z-index:6;display:inline-flex;align-items:center;justify-content:center;width:48px;height:48px;padding:0;border:1px solid rgba(15,23,42,.16);border-radius:999px;background:rgba(255,255,255,.94);color:#173b63;box-shadow:0 8px 24px rgba(15,23,42,.18);cursor:pointer;transform:translateY(-50%)}' +
      '.sv-product-gallery-arrow:focus-visible{outline:3px solid rgba(11,79,136,.34);outline-offset:3px}.sv-product-gallery-arrow--previous{left:14px}.sv-product-gallery-arrow--next{right:14px}.sv-product-gallery-arrow svg{width:24px;height:24px;pointer-events:none}' +
      '.sv-product-gallery-counter{position:absolute;right:14px;bottom:14px;z-index:6;min-width:54px;padding:5px 10px;border-radius:999px;background:rgba(15,23,42,.78);color:#fff;font-size:.78rem;font-weight:700;line-height:1.2;text-align:center;pointer-events:none}' +
      '@media(max-width:640px){.sv-product-gallery-arrow{width:44px;height:44px}.sv-product-gallery-arrow--previous{left:8px}.sv-product-gallery-arrow--next{right:8px}.sv-product-gallery-counter{right:8px;bottom:8px}}';
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
        var shouldRun = !this.paused && this.visible;
        if (!shouldRun) {
          if (this.timer) clearInterval(this.timer);
          this.timer = null;
          return;
        }
        if (!this.timer) this.timer = setInterval(advance, ROTATION_INTERVAL);
      },
      pause: function () {
        this.paused = true;
        this.sync();
      },
      resume: function () {
        this.paused = false;
        this.sync();
      },
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
    var carousel = createCarousel(gallery, function () {
      select(currentIndex + 1);
    });

    thumbnails.forEach(function (button, index) {
      button.addEventListener('click', function () {
        currentIndex = index;
        if (!advancing) carousel.pauseFor(10000);
        updateControls();
      });
    });
    previous.addEventListener('click', function (event) {
      event.preventDefault();
      event.stopPropagation();
      select(currentIndex - 1);
      carousel.pauseFor(10000);
    });
    next.addEventListener('click', function (event) {
      event.preventDefault();
      event.stopPropagation();
      select(currentIndex + 1);
      carousel.pauseFor(10000);
    });
    gallery.addEventListener('keydown', function (event) {
      if (event.key === 'ArrowLeft') { event.preventDefault(); select(currentIndex - 1); carousel.pauseFor(10000); }
      if (event.key === 'ArrowRight') { event.preventDefault(); select(currentIndex + 1); carousel.pauseFor(10000); }
    });
    gallery.addEventListener('touchstart', function (event) {
      if (event.touches.length === 1) touchStartX = event.touches[0].clientX;
    }, { passive: true });
    gallery.addEventListener('touchend', function (event) {
      if (touchStartX === null || event.changedTouches.length !== 1) return;
      var distance = event.changedTouches[0].clientX - touchStartX;
      touchStartX = null;
      if (Math.abs(distance) < 48) return;
      select(currentIndex + (distance < 0 ? 1 : -1));
      carousel.pauseFor(10000);
    }, { passive: true });
    pauseOnInteraction(gallery, carousel);
    updateControls();
  }

  function initProductCardCarousels() {
    document.querySelectorAll('.product-image[data-images], .category-slide-image-wrapper[data-images]').forEach(function (element) {
      if (carousels.has(element)) return;

      var image = element.querySelector('img');
      var images = parseImages(element.getAttribute('data-images'));
      var currentSrc = image && (image.getAttribute('src') || image.currentSrc);
      if (currentSrc && images.indexOf(currentSrc) === -1) images.unshift(currentSrc);
      if (!image || images.length < 2) return;

      var currentIndex = Math.max(0, images.indexOf(currentSrc));
      var carousel = createCarousel(element, function () {
        currentIndex = (currentIndex + 1) % images.length;
        image.style.transition = 'opacity 0.25s ease-in-out';
        image.style.opacity = '0.7';
        image.src = images[currentIndex];
        setTimeout(function () { image.style.opacity = '1'; }, 100);
      });
      image.addEventListener('error', function () {
        currentIndex = (currentIndex + 1) % images.length;
        image.src = images[currentIndex];
      });
      pauseOnInteraction(element, carousel);
    });
  }

  function initAll() {
    initProductGallery();
    initProductCardCarousels();
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', initAll, { once: true });
  else initAll();

  var observer = new MutationObserver(function () { window.setTimeout(initProductCardCarousels, 50); });
  observer.observe(document.body, { childList: true, subtree: true });

  window.addEventListener('beforeunload', function () {
    carousels.forEach(function (carousel) { carousel.destroy(); });
    observer.disconnect();
    if (visibilityObserver) visibilityObserver.disconnect();
  });
})();

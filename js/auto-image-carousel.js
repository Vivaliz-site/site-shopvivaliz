/**
 * AUTO IMAGE CAROUSEL - 3 SEGUNDOS
 * Alterna automaticamente entre imagens em todas as paginas.
 */

(function() {
  'use strict';

  const carousels = new Map();
  const ROTATION_INTERVAL = 3000;

  function parseImages(rawValue) {
    if (!rawValue) return [];

    const candidates = [rawValue];
    try {
      candidates.push(decodeURIComponent(rawValue));
    } catch (e) {}
    candidates.push(rawValue.replace(/&quot;/g, '"').replace(/&#039;/g, "'").replace(/&amp;/g, '&'));

    for (const candidate of candidates) {
      try {
        const parsed = JSON.parse(candidate);
        if (Array.isArray(parsed)) {
          return parsed
            .map(value => String(value || '').trim())
            .filter(Boolean);
        }
      } catch (e) {}
    }

    return [];
  }

  function initProductGallery() {
    const thumbnailButtons = document.querySelectorAll('.product-gallery-thumbnails .thumb-btn');
    if (thumbnailButtons.length < 2 || window.__svProductGalleryCarousel) return;
    window.__svProductGalleryCarousel = true;

    let currentImageIndex = 0;
    let isAutoPlay = true;
    let autoCarouselInterval = null;

    function startAutoCarousel() {
      if (autoCarouselInterval) clearInterval(autoCarouselInterval);

      autoCarouselInterval = setInterval(() => {
        if (!isAutoPlay || thumbnailButtons.length === 0) return;

        currentImageIndex = (currentImageIndex + 1) % thumbnailButtons.length;
        const nextButton = thumbnailButtons[currentImageIndex];
        if (nextButton) nextButton.click();
      }, ROTATION_INTERVAL);
    }

    thumbnailButtons.forEach((button, index) => {
      button.addEventListener('click', () => {
        currentImageIndex = index;
        isAutoPlay = false;
        clearTimeout(window.autoCarouselResumeTimer);
        window.autoCarouselResumeTimer = setTimeout(() => {
          isAutoPlay = true;
          startAutoCarousel();
        }, 10000);
      });
    });

    startAutoCarousel();

    window.addEventListener('beforeunload', () => {
      if (autoCarouselInterval) clearInterval(autoCarouselInterval);
    });
  }

  function initProductCardCarousels() {
    document.querySelectorAll('.product-image[data-images], .product-card .product-image').forEach(element => {
      if (carousels.has(element)) return;

      const img = element.querySelector('img');
      if (!img) return;

      let imagesJson = element.getAttribute('data-images');
      if (!imagesJson) {
        const article = element.closest('article');
        const link = article ? article.querySelector('a.product-image[data-images]') : null;
        if (link) imagesJson = link.getAttribute('data-images');
      }
      if (!imagesJson) return;

      const images = parseImages(imagesJson);
      const currentSrc = img.getAttribute('src') || '';
      if (currentSrc && !images.includes(currentSrc)) {
        images.unshift(currentSrc);
      }
      if (images.length < 2) return;

      let currentIndex = Math.max(0, images.indexOf(currentSrc));
      let isAutoPlay = true;
      let timer = null;

      function setCurrentImage(nextIndex) {
        currentIndex = nextIndex % images.length;
        element.setAttribute('data-current-index', String(currentIndex));
        img.style.opacity = '0.72';
        img.src = images[currentIndex];
        setTimeout(() => {
          img.style.opacity = '1';
        }, 180);
      }

      function startRotation() {
        if (timer) clearInterval(timer);
        timer = setInterval(() => {
          if (isAutoPlay) setCurrentImage(currentIndex + 1);
        }, ROTATION_INTERVAL);
      }

      img.addEventListener('mouseenter', () => {
        isAutoPlay = false;
      });

      img.addEventListener('mouseleave', () => {
        isAutoPlay = true;
      });

      img.addEventListener('error', () => {
        if (images.length > 1) setCurrentImage(currentIndex + 1);
      });

      element.setAttribute('data-current-index', String(currentIndex));
      startRotation();
      carousels.set(element, { timer, images });
    });
  }

  function initAll() {
    initProductGallery();
    initProductCardCarousels();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initAll);
  } else {
    initAll();
  }

  const observer = new MutationObserver(() => {
    setTimeout(initProductCardCarousels, 100);
  });

  observer.observe(document.body, {
    childList: true,
    subtree: true,
  });

  window.addEventListener('beforeunload', () => {
    carousels.forEach(carousel => {
      if (carousel.timer) clearInterval(carousel.timer);
    });
    observer.disconnect();
  });
})();

/**
 * AUTO IMAGE CAROUSEL - 3 SEGUNDOS
 * Alterna automaticamente entre imagens em todas as páginas
 * Suporta:
 * 1. Página de produto: .product-gallery-thumbnails .thumb-btn
 * 2. Catálogo/listagens: .product-card .product-image com data-images
 */

(function() {
  'use strict';

  const carousels = new Map(); // Map de <elemento> -> { interval, currentIndex, images }
  const ROTATION_INTERVAL = 3000; // 3 segundos

  function initProductGallery() {
    const thumbnailButtons = document.querySelectorAll('.product-gallery-thumbnails .thumb-btn');
    if (thumbnailButtons.length > 1) {
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
  }

  function initProductCardCarousels() {
    let count = 0;
    document.querySelectorAll('.product-card .product-image').forEach(element => {
      const img = element.querySelector('img');
      if (!img) {
        console.log('[Carousel] Element has no img');
        return;
      }

      let imagesJson = element.getAttribute('data-images');
      if (!imagesJson) {
        const link = element.closest('article').querySelector('a.product-image');
        if (link) imagesJson = link.getAttribute('data-images');
      }
      if (!imagesJson) {
        console.log('[Carousel] No data-images attribute');
        return;
      }

      try {
        const unescaped = imagesJson.replace(/&quot;/g, '"');
        const images = JSON.parse(unescaped);
        if (!Array.isArray(images) || images.length < 2) {
          console.log('[Carousel] Less than 2 images:', images.length);
          return;
        }
        count++;

        let currentIndex = 0;
        let isAutoPlay = true;
        let timer = null;

        function startRotation() {
          if (timer) clearInterval(timer);

          timer = setInterval(() => {
            if (!isAutoPlay) return;

            currentIndex = (currentIndex + 1) % images.length;
            img.style.opacity = '0.7';
            img.src = images[currentIndex];

            setTimeout(() => {
              img.style.opacity = '1';
            }, 200);
          }, ROTATION_INTERVAL);
        }

        img.addEventListener('mouseenter', () => {
          isAutoPlay = false;
          if (timer) clearInterval(timer);
        });

        img.addEventListener('mouseleave', () => {
          isAutoPlay = true;
          startRotation();
        });

        element.addEventListener('click', () => {
          isAutoPlay = false;
          if (timer) clearInterval(timer);
        });

        startRotation();

        carousels.set(element, { timer, images });
      } catch (e) {
        console.error('Erro ao parsear imagens do produto:', e);
      }
    });
    console.log('[Carousel] Initialized', count, 'product carousels');
  }

  function initAll() {
    console.log('[Carousel] Initializing...');
    initProductGallery();
    initProductCardCarousels();
    console.log('[Carousel] Init complete');
  }

  if (document.readyState === 'loading') {
    console.log('[Carousel] Waiting for DOMContentLoaded');
    document.addEventListener('DOMContentLoaded', initAll);
  } else {
    console.log('[Carousel] DOM already loaded, initializing now');
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

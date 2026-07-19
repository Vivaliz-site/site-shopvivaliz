/**
 * AUTO IMAGE CAROUSEL - 3 SEGUNDOS
 * Alterna automaticamente entre imagens em todas as páginas
 * Funciona na página de produto e qualquer página com .product-gallery-thumbnails
 */

(function() {
  'use strict';

  let autoCarouselInterval = null;
  let currentImageIndex = 0;
  let thumbnailButtons = [];
  let isAutoPlay = true;

  function initAutoCarousel() {
    thumbnailButtons = document.querySelectorAll('.product-gallery-thumbnails .thumb-btn');

    if (thumbnailButtons.length === 0) {
      return;
    }

    startAutoCarousel();
    addClickListeners();
  }

  function startAutoCarousel() {
    if (autoCarouselInterval) {
      clearInterval(autoCarouselInterval);
    }

    autoCarouselInterval = setInterval(() => {
      if (!isAutoPlay || thumbnailButtons.length === 0) {
        return;
      }

      currentImageIndex = (currentImageIndex + 1) % thumbnailButtons.length;
      const nextButton = thumbnailButtons[currentImageIndex];

      if (nextButton) {
        nextButton.click();
      }
    }, 3000); // 3 segundos
  }

  function addClickListeners() {
    thumbnailButtons.forEach((button, index) => {
      button.addEventListener('click', () => {
        currentImageIndex = index;
        isAutoPlay = false;

        // Resume autoplay após 10 segundos de inatividade
        clearTimeout(window.autoCarouselResumeTimer);
        window.autoCarouselResumeTimer = setTimeout(() => {
          isAutoPlay = true;
          startAutoCarousel();
        }, 10000);
      });
    });
  }

  // Inicializar quando o DOM estiver pronto
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initAutoCarousel);
  } else {
    initAutoCarousel();
  }

  // Reinicializar se novos elementos forem adicionados dinamicamente
  const observer = new MutationObserver(() => {
    const newButtons = document.querySelectorAll('.product-gallery-thumbnails .thumb-btn');
    if (newButtons.length !== thumbnailButtons.length) {
      initAutoCarousel();
    }
  });

  observer.observe(document.body, {
    childList: true,
    subtree: true,
  });

  // Cleanup
  window.addEventListener('beforeunload', () => {
    if (autoCarouselInterval) {
      clearInterval(autoCarouselInterval);
    }
    observer.disconnect();
  });
})();

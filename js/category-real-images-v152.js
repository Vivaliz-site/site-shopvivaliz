(function () {
  'use strict';

  var cards = Array.prototype.slice.call(document.querySelectorAll('.home-categories .category-slide'));
  if (!cards.length) return;

  function normalize(value) {
    return String(value || '')
      .normalize('NFD')
      .replace(/[\u0300-\u036f]/g, '')
      .trim()
      .toLowerCase()
      .replace(/\s+/g, ' ');
  }

  function markReady(card) {
    card.classList.add('is-category-ready');
    card.removeAttribute('aria-busy');
  }

  cards.forEach(function (card) {
    card.setAttribute('aria-busy', 'true');
    markReady(card);
  });

  function render(rows) {
    var byCategory = {};

    rows.forEach(function (row) {
      var key = normalize(row && row.category);
      if (key && row && row.image_url && !byCategory[key]) {
        byCategory[key] = row;
      }
    });

    cards.forEach(function (card) {
      var title = card.querySelector('strong');
      var wrapper = card.querySelector('.category-slide-image-wrapper');
      var row = title ? byCategory[normalize(title.textContent)] : null;

      if (!title || !wrapper || !row || !row.image_url) {
        card.classList.add('uses-category-fallback');
        return;
      }

      var image = document.createElement('img');
      image.className = 'category-slide-real-image';
      image.src = row.image_url;
      image.alt = 'Produto real da categoria ' + title.textContent.trim();
      image.loading = 'lazy';
      image.decoding = 'async';
      image.width = 320;
      image.height = 220;

      image.addEventListener('load', function () {
        wrapper.replaceChildren(image);
        card.classList.add('has-real-image');
        card.classList.remove('uses-category-fallback');
      });

      image.addEventListener('error', function () {
        card.classList.add('uses-category-fallback');
      });

      card.dataset.categoryImageSku = String(row.sku || '');
      card.dataset.categoryImageProduct = String(row.product_name || '');
    });
  }

  fetch('/api/catalog/category-images.php', {
    credentials: 'same-origin',
    cache: 'no-store',
    headers: { Accept: 'application/json' }
  })
    .then(function (response) {
      if (!response.ok) throw new Error('category_images_unavailable');
      return response.json();
    })
    .then(function (payload) {
      render(Array.isArray(payload.categories) ? payload.categories : []);
    })
    .catch(function () {
      cards.forEach(function (card) {
        card.classList.add('uses-category-fallback');
      });
    });
})();

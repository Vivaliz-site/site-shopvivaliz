(function(){
  function invalid(src){
    var value=String(src||'').toLowerCase();
    return !value||value.indexOf('placeholder')!==-1||value.indexOf('logo-vivaliz')!==-1;
  }

  function verifyCard(card) {
    if (card.dataset.integrityVerified === '1') return;
    card.dataset.integrityVerified = '1';
    var image = card.querySelector('.product-image img');
    if (!image) {
      console.warn('[ImageIntegrity] No image tag found in card');
      return;
    }
    
    var src = image.getAttribute('src');
    if (invalid(src)) {
      console.warn('[ImageIntegrity] Removing card due to invalid src:', src);
      card.remove();
      return;
    }
    
    image.addEventListener('error', function() {
      console.error('[ImageIntegrity] Image load error. Removing card. Src:', src);
      card.remove();
    });
    
    image.addEventListener('load', function() {
      console.log('[ImageIntegrity] Image loaded successfully. Src:', src);
      card.classList.add('has-real-product-image');
    });
    
    if (image.complete && image.naturalWidth > 0) {
      console.log('[ImageIntegrity] Image complete (cached). Src:', src);
      card.classList.add('has-real-product-image');
    }
  }

  var cards = Array.prototype.slice.call(document.querySelectorAll('.product-card'));
  cards.forEach(verifyCard);

  var grid = document.getElementById('product-grid');
  if (grid) {
    var observer = new MutationObserver(function() {
      var currentCards = Array.prototype.slice.call(grid.querySelectorAll('.product-card'));
      currentCards.forEach(verifyCard);
      
      var remaining = grid.querySelectorAll('.product-card').length;
      grid.classList.toggle('is-empty-after-image-validation', remaining === 0);
    });
    observer.observe(grid, { childList: true, subtree: true });
  }
})();

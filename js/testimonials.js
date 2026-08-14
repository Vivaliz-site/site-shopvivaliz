(function () {
  var section = document.querySelector('.home-testimonials');
  if (!section) return;
  var grid = section.querySelector('.testimonials-grid');
  var intro = section.querySelector('.section-heading p');
  function safe(value) {
    var node = document.createElement('span');
    node.textContent = String(value || '');
    return node.innerHTML;
  }
  function render(item) {
    var rating = Math.max(1, Math.min(5, Number(item.rating || 5)));
    var name = String(item.name || 'Cliente');
    var initials = name.split(/\s+/).slice(0, 2).map(function (part) { return part.charAt(0).toUpperCase(); }).join('');
    var stars = '';
    for (var i = 0; i < 5; i += 1) stars += i < rating ? '★' : '☆';
    return '<article class="testimonial-card"><div class="testimonial-stars" aria-label="' + rating + ' de 5 estrelas">' + stars + '</div><p>“' + safe(item.message) + '”</p><div class="testimonial-author"><span class="testimonial-avatar testimonial-initials">' + safe(initials) + '</span><div><strong>' + safe(name) + '</strong><span>' + safe(item.city || 'Cliente') + '</span></div></div></article>';
  }
  fetch('/api/testimonials.php', { headers: { Accept: 'application/json' } })
    .then(function (response) { return response.json(); })
    .then(function (data) {
      var items = Array.isArray(data.items) ? data.items : [];
      if (items.length) {
        grid.innerHTML = items.slice(0, 6).map(render).join('');
        if (intro) intro.textContent = 'Avaliações reais publicadas após moderação automática da Liz.';
      } else {
        grid.innerHTML = '<div class="testimonial-empty"><p>Ainda não há avaliações publicadas.</p><a class="btn btn-primary" href="/avaliacoes.php">Seja o primeiro a avaliar</a></div>';
        if (intro) intro.textContent = 'Envie sua experiência. A Liz faz a moderação automática antes da publicação.';
      }
      var link = document.createElement('p');
      link.className = 'testimonial-submit-link';
      link.innerHTML = '<a href="/avaliacoes.php">Enviar uma avaliação</a>';
      grid.insertAdjacentElement('afterend', link);
    })
    .catch(function () {
      grid.innerHTML = '<div class="testimonial-empty"><p>As avaliações estão temporariamente indisponíveis.</p><a class="btn btn-primary" href="/avaliacoes.php">Enviar avaliação</a></div>';
    });
})();

(function () {
'use strict';
if (window.__svPublicExperienceInitialized) return;
window.__svPublicExperienceInitialized = true;

var framePending = false;
var visibilityFramePending = false;
var testimonialsInstalled = false;

function esc(value) {
  return String(value || '').replace(/[&<>"']/g, function (char) {
    return ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot',"'":'&#039;'})[char];
  });
}
function initials(name) {
  return String(name || 'Cliente').trim().split(/\s+/).slice(0, 2).map(function (part) {
    return part.charAt(0).toUpperCase();
  }).join('') || 'C';
}
function stars(value) {
  var rating = Math.max(1, Math.min(5, Number(value) || 5));
  return '★'.repeat(rating) + '☆'.repeat(5 - rating);
}
function isPublicPath(path) {
  return !/^\/(?:admin|api|auth|checkout|painel|claude|mcp)\b/i.test(String(path || ''));
}
function syncBottomUiOffset() {
  var root = document.documentElement;
  if (!root) return;
  if (window.innerWidth > 820) {
    root.style.setProperty('--sv-bottom-ui-offset', '0px');
    return;
  }
  var selectors = ['.sv-mobile-nav-bar', '.sv-mobile-bottom-nav', '.sv-checkout-mobile-total', '.sv-mobile-buybar'];
  var maxOffset = 0;
  selectors.forEach(function (selector) {
    document.querySelectorAll(selector).forEach(function (node) {
      if (!(node instanceof HTMLElement)) return;
      var style = window.getComputedStyle(node);
      if (style.display === 'none' || style.visibility === 'hidden' || style.position !== 'fixed') return;
      var rect = node.getBoundingClientRect();
      if (rect.width <= 0 || rect.height <= 0) return;
      maxOffset = Math.max(maxOffset, Math.ceil(Math.max(0, window.innerHeight - rect.top) + 12));
    });
  });
  root.style.setProperty('--sv-bottom-ui-offset', maxOffset > 0 ? maxOffset + 'px' : '0px');
}
function installTestimonials() {
  if (testimonialsInstalled) return;
  var section = document.querySelector('.home-testimonials');
  if (!section) return;
  var grid = section.querySelector('.testimonials-grid');
  if (!grid) return;
  testimonialsInstalled = true;
  var intro = section.querySelector('.section-heading p');
  if (intro) intro.textContent = 'Avaliações enviadas por clientes e publicadas somente após moderação da equipe.';
  grid.innerHTML = '<div class="sv-testimonial-empty">Carregando avaliações reais...</div>';
  fetch('/api/testimonials.php', {headers:{Accept:'application/json'}, credentials:'same-origin'})
    .then(function (response) {
      if (!response.ok) throw new Error('testimonial_request_failed');
      return response.json();
    })
    .then(function (data) {
      var items = data && Array.isArray(data.items) ? data.items : [];
      if (!items.length) {
        grid.innerHTML = '<div class="sv-testimonial-empty"><strong>Ainda não há avaliações publicadas.</strong><br>Se você já comprou na ShopVivaliz, compartilhe sua experiência para análise da equipe.</div>';
      } else {
        grid.innerHTML = items.slice(0, 6).map(function (item) {
          var city = String(item.city || '').trim();
          return '<article class="testimonial-card"><div class="testimonial-stars" aria-label="' + esc(item.rating) + ' de 5 estrelas">' + esc(stars(item.rating)) + '</div><p>“' + esc(item.message) + '”</p><div class="testimonial-author"><span class="testimonial-avatar sv-initials" aria-hidden="true">' + esc(initials(item.name)) + '</span><div><strong>' + esc(item.name) + '</strong>' + (city ? '<span>' + esc(city) + '</span>' : '') + '<small class="sv-moderated-label">✓ Avaliação moderada</small></div></div></article>';
        }).join('');
      }
      if (!section.querySelector('.sv-testimonial-actions')) {
        var actions = document.createElement('div');
        actions.className = 'sv-testimonial-actions';
        actions.innerHTML = '<a class="sv-testimonial-primary" href="/avaliacoes.php">Enviar minha avaliação</a><a class="sv-testimonial-secondary" href="/avaliacoes.php#como-funciona">Como funciona a moderação</a>';
        grid.insertAdjacentElement('afterend', actions);
      }
      scheduleResponsivePass();
    })
    .catch(function () {
      grid.innerHTML = '<div class="sv-testimonial-empty">Não foi possível carregar as avaliações agora. Tente novamente mais tarde.</div>';
    });
}
function installSupport() {
  if (!isPublicPath(window.location.pathname)) return;
  if (document.querySelector('.sv-support-dock')) return;
  var config = window.ShopVivalizPublicConfig || {};
  var dock = document.createElement('div');
  dock.className = 'sv-support-dock';
  dock.setAttribute('aria-label', 'Canais de atendimento');
  var whatsapp = config.whatsappUrl || '/contato';
  var external = /^https?:\/\//i.test(String(whatsapp));
  var extra = external ? ' target="_blank" rel="noopener noreferrer"' : '';
  dock.innerHTML = '<a class="sv-support-button sv-support-whatsapp" href="' + esc(whatsapp) + '"' + extra + ' aria-label="Falar com a ShopVivaliz pelo WhatsApp"><svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true"><path d="M20.52 3.48A11.87 11.87 0 0 0 12.05 0C5.5 0 .16 5.33.16 11.89c0 2.1.55 4.14 1.6 5.93L.06 24l6.32-1.66a11.86 11.86 0 0 0 5.67 1.45h.01c6.55 0 11.89-5.33 11.89-11.89 0-3.18-1.22-6.17-3.43-8.42Z"/></svg><span class="sv-support-label">WhatsApp</span></a>';
  document.body.appendChild(dock);
}
function installMobileNav() {
  if (window.innerWidth > 820 || !isPublicPath(window.location.pathname)) return;
  if (document.querySelector('.sv-mobile-nav-bar,.sv-mobile-bottom-nav,.sv-checkout-mobile-total,.sv-mobile-buybar')) return;
  var nav = document.createElement('nav');
  var path = window.location.pathname || '/';
  var links = [
    {href:'/',label:'Início',icon:'⌂',active:path==='/'},
    {href:'/catalogo',label:'Busca',icon:'⌕',active:/^\/catalogo\b/i.test(path)},
    {href:'/carrinho',label:'Carrinho',icon:'🛒',active:/^\/carrinho\b/i.test(path)},
    {href:'/blog/',label:'Blog',icon:'✎',active:/^\/blog\b/i.test(path)},
    {href:'/auth/login.php',label:'Conta',icon:'◉',active:/^\/auth\b/i.test(path)}
  ];
  nav.className = 'sv-mobile-nav-bar';
  nav.setAttribute('aria-label', 'Navegação rápida');
  nav.innerHTML = links.map(function (link) {
    var attrs = link.active ? ' aria-current="page" class="is-active"' : '';
    return '<a href="' + link.href + '"' + attrs + '><span class="nav-icon" aria-hidden="true">' + link.icon + '</span><span>' + link.label + '</span></a>';
  }).join('');
  document.body.appendChild(nav);
}
function updatePageState() {
  var body = document.body;
  if (!body) return;
  var path = window.location.pathname || '/';
  var isProduct = /^\/produto(?:\/|$)/i.test(path);
  var isCatalog = /^\/catalogo(?:\/|$)/i.test(path);
  var isHome = path === '/';
  var nearTop = window.scrollY < 420;

  body.classList.toggle('sv-page-product', isProduct);
  body.classList.toggle('sv-product-top', isProduct && nearTop);
  body.classList.toggle('sv-page-catalog', isCatalog);
  body.classList.toggle('sv-catalog-top', isCatalog && nearTop);
  body.classList.toggle('sv-page-home', isHome);
  body.classList.toggle('sv-home-top', isHome && nearTop);
}
function updateFooterVisibility() {
  var body = document.body;
  if (!body) return;
  var footer = document.querySelector('footer');
  if (!footer) {
    body.classList.remove('sv-footer-visible');
    return;
  }
  body.classList.toggle('sv-footer-visible', footer.getBoundingClientRect().top < window.innerHeight - 72);
}
function runVisibilityPass() {
  visibilityFramePending = false;
  updatePageState();
  updateFooterVisibility();
}
function scheduleVisibilityPass() {
  if (visibilityFramePending) return;
  visibilityFramePending = true;
  window.requestAnimationFrame(runVisibilityPass);
}
function runResponsivePass() {
  framePending = false;
  installMobileNav();
  syncBottomUiOffset();
  updatePageState();
  updateFooterVisibility();
}
function scheduleResponsivePass() {
  if (framePending) return;
  framePending = true;
  window.requestAnimationFrame(runResponsivePass);
}
function init() {
  installTestimonials();
  installSupport();
  scheduleResponsivePass();
  if (typeof MutationObserver !== 'undefined' && document.body) {
    new MutationObserver(function (mutations) {
      var relevant = mutations.some(function (mutation) { return mutation.addedNodes.length || mutation.removedNodes.length; });
      if (relevant) scheduleResponsivePass();
    }).observe(document.body, {childList:true, subtree:true});
  }
}
if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init, {once:true});
else init();
window.addEventListener('resize', scheduleResponsivePass, {passive:true});
window.addEventListener('orientationchange', scheduleResponsivePass, {passive:true});
window.addEventListener('scroll', scheduleVisibilityPass, {passive:true});
window.addEventListener('load', scheduleResponsivePass, {once:true});
})();

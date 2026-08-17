(function () {
'use strict';
if (window.__svPublicExperienceInitialized) return;
window.__svPublicExperienceInitialized = true;

var framePending = false;
var visibilityFramePending = false;
var testimonialsInstalled = false;
var homeCategoryImagesInstalled = false;

function esc(value) {
  return String(value || '').replace(/[&<>"']/g, function (char) {
    return ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'})[char];
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
function isHomePath(path) {
  var value = String(path || '');
  return value === '/' || value === '/index.php';
}
function normalizeText(value) {
  return String(value || '').toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g, '').trim();
}
function isValidCatalogImage(value) {
  var url = String(value || '').trim();
  if (!url || /placeholder|logo-vivaliz|no[-_ ]?image|sem[-_ ]?imagem|unsplash\.com/i.test(url)) return false;
  return /^https?:\/\//i.test(url) || url.charAt(0) === '/';
}
function rowImages(row) {
  var values = [];
  ['image_url','image','imagem_principal_url','primary_image_url'].forEach(function (field) {
    if (isValidCatalogImage(row && row[field])) values.push(String(row[field]).trim());
  });
  ['images','imagens','gallery','galeria'].forEach(function (field) {
    var list = row && Array.isArray(row[field]) ? row[field] : [];
    list.forEach(function (value) {
      var candidate = value && typeof value === 'object'
        ? (value.url || value.image_url || value.src || '')
        : value;
      if (isValidCatalogImage(candidate)) values.push(String(candidate).trim());
    });
  });
  return values.filter(function (value, index, all) { return all.indexOf(value) === index; });
}
function imageQualityScore(url) {
  var value = normalizeText(url);
  var score = /ambient|lifestyle|context|uso|scene|cenario/.test(value) ? 12 : 0;
  if (/thumb|thumbnail|small|mini|preview/.test(value)) score -= 10;
  return score;
}
function bestRowImage(row) {
  var images = rowImages(row);
  images.sort(function (a, b) { return imageQualityScore(b) - imageQualityScore(a); });
  return images[0] || '';
}
function categoryCandidateScore(categoryName, row) {
  var category = normalizeText(categoryName);
  var rowCategory = normalizeText(row && row.category);
  var name = normalizeText(row && row.name);
  var image = bestRowImage(row);
  if (!image) return -999;
  var score = rowCategory === category ? 24 : 0;
  score += Number(row && row.stock) > 0 ? 8 : 0;
  score += Number(row && row.price) > 0 ? 4 : 0;
  score += imageQualityScore(image);
  var tokens = category.split(/[^a-z0-9]+/).filter(function (token) {
    return token.length >= 4 && ['para','com','sem','equipamentos','moveis'].indexOf(token) === -1;
  });
  tokens.forEach(function (token) { if (name.indexOf(token) !== -1) score += 6; });
  if (category.indexOf('caixa') !== -1 && category.indexOf('ferrament') !== -1) {
    var isToolbox = (name.indexOf('caixa') !== -1 || name.indexOf('maleta') !== -1) && name.indexOf('ferrament') !== -1;
    score += isToolbox ? 45 : -40;
  }
  if (category.indexOf('roda') !== -1 || category.indexOf('rodizio') !== -1) {
    score += (name.indexOf('roda') !== -1 || name.indexOf('rodizio') !== -1 || name.indexOf('caster') !== -1) ? 30 : -20;
  }
  if (category.indexOf('vaso') !== -1 && name.indexOf('vaso') !== -1) score += 25;
  return score;
}
function localCategoryFallback(categoryName) {
  var category = normalizeText(categoryName);
  if (/rodizio|roda/.test(category)) return '/public/assets/category-images/cat-rodizios.jpg';
  if (/jardim|floreira|vaso/.test(category)) return '/public/assets/category-images/cat-jardim.jpg';
  if (/fixacao|ferragem|cadeado|seguranca/.test(category)) return '/public/assets/category-images/cat-ferragens.jpg';
  if (/ferrament|pintura|construcao|eletric|automotiv/.test(category)) return '/public/assets/category-images/cat-ferramentas.jpg';
  return '/public/assets/category-images/cat-organizacao.jpg';
}
function extractCatalogRows(data) {
  if (Array.isArray(data)) return data;
  if (data && Array.isArray(data.products)) return data.products;
  if (data && Array.isArray(data.items)) return data.items;
  if (data && Array.isArray(data.data)) return data.data;
  return [];
}
function fetchCatalogPage(page) {
  return fetch('/api/catalog/products.php?limit=200&available=1&page=' + encodeURIComponent(String(page)), {
    headers:{Accept:'application/json'},
    credentials:'same-origin'
  }).then(function (response) {
    if (!response.ok) throw new Error('category_catalog_request_failed');
    return response.json();
  }).then(function (data) {
    return {
      rows: extractCatalogRows(data).filter(function (row) { return row && typeof row === 'object'; }),
      totalPages: Math.max(1, Number(data && data.total_pages) || 1)
    };
  });
}
function fetchAllCatalogRows() {
  var rows = [];
  function load(page, knownTotalPages) {
    return fetchCatalogPage(page).then(function (result) {
      rows = rows.concat(result.rows);
      var totalPages = Math.max(knownTotalPages || 1, result.totalPages || 1);
      if (page < totalPages) return load(page + 1, totalPages);
      return rows;
    });
  }
  return load(1, 1);
}
function markCategoryFallback(image, categoryName, preferredFallback) {
  if (!(image instanceof HTMLImageElement)) return;
  var fallback = isValidCatalogImage(preferredFallback)
    ? String(preferredFallback)
    : localCategoryFallback(categoryName);
  image.onerror = null;
  image.src = fallback;
  image.alt = 'Produto da categoria ' + categoryName;
  image.dataset.svCategorySource = 'local-fallback';
  delete image.dataset.svProductSku;
}
function installHomeCategoryImages() {
  if (homeCategoryImagesInstalled || !isHomePath(window.location.pathname)) return;
  var cards = Array.prototype.slice.call(document.querySelectorAll('.home-categories .category-slide'));
  if (!cards.length) return;
  homeCategoryImagesInstalled = true;
  fetchAllCatalogRows()
    .then(function (rows) {
      var used = Object.create(null);
      cards.forEach(function (card) {
        var label = card.querySelector('strong');
        var image = card.querySelector('img.category-slide-img');
        if (!label || !(image instanceof HTMLImageElement)) return;
        var categoryName = String(label.textContent || '').trim();
        var normalizedCategory = normalizeText(categoryName);
        var serverRenderedSrc = String(image.currentSrc || image.getAttribute('src') || '').trim();
        var candidates = rows.filter(function (row) {
          var sameCategory = normalizeText(row.category) === normalizedCategory;
          var name = normalizeText(row.name);
          var semanticRepair = normalizedCategory.indexOf('caixa') !== -1 && normalizedCategory.indexOf('ferrament') !== -1
            && (name.indexOf('caixa') !== -1 || name.indexOf('maleta') !== -1)
            && name.indexOf('ferrament') !== -1;
          return sameCategory || semanticRepair;
        }).map(function (row) {
          return {row:row, image:bestRowImage(row), score:categoryCandidateScore(categoryName, row)};
        }).filter(function (candidate) {
          return candidate.image && candidate.score > -30;
        }).sort(function (a, b) { return b.score - a.score; });
        var chosen = null;
        for (var i = 0; i < candidates.length; i += 1) {
          if (!used[candidates[i].image]) { chosen = candidates[i]; break; }
        }
        if (chosen) {
          image.onerror = function () {
            markCategoryFallback(image, categoryName, serverRenderedSrc);
          };
          image.src = chosen.image;
          image.alt = String(chosen.row.name || ('Produto da categoria ' + categoryName));
          image.dataset.svCategorySource = 'catalog';
          image.dataset.svProductSku = String(chosen.row.sku || chosen.row.id || '');
          used[chosen.image] = true;
        } else {
          // Nunca reutiliza deliberadamente uma imagem ja usada. Se a categoria
          // nao tiver uma alternativa real unica, prefere fallback local.
          markCategoryFallback(image, categoryName, serverRenderedSrc);
        }
        image.loading = 'lazy';
        image.decoding = 'async';
      });
    })
    .catch(function () {
      cards.forEach(function (card) {
        var label = card.querySelector('strong');
        var image = card.querySelector('img.category-slide-img');
        if (!label || !(image instanceof HTMLImageElement)) return;
        markCategoryFallback(image, String(label.textContent || '').trim(), image.currentSrc || image.getAttribute('src') || '');
      });
    });
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
  var nav = document.querySelector('.sv-mobile-nav-bar');
  var path = window.location.pathname || '/';
  var cartCount = 0;
  try {
    var items = window.ShopVivalizCart && typeof window.ShopVivalizCart.get === 'function'
      ? window.ShopVivalizCart.get()
      : JSON.parse(localStorage.getItem('shopvivaliz_cart') || '[]');
    cartCount = Array.isArray(items) ? items.reduce(function (sum, item) {
      return sum + (Number(item && item.quantity) || 1);
    }, 0) : 0;
  } catch (error) {
    cartCount = 0;
  }

  var links = [
    {href:'/',label:'Início',icon:'⌂',active:isHomePath(path)},
    {href:'/catalogo/',label:'Categorias',icon:'▦',active:/^\/catalogo\b/i.test(path)},
    {href:'/catalogo/',label:'Busca',icon:'⌕',active:/^\/catalogo\b/i.test(path)},
    {href:'/carrinho',label:'Carrinho',icon:'🛒',active:/^\/carrinho\b/i.test(path),badge:cartCount},
    {href:'/contato/',label:'Liz/Ajuda',icon:'✆',active:/^\/contato\b/i.test(path) || /sv-liz/.test(location.hash || '')}
  ];

  if (!nav) {
    nav = document.createElement('nav');
    nav.className = 'sv-mobile-nav-bar';
    nav.setAttribute('aria-label', 'Navegação rápida');
    document.body.appendChild(nav);
  }

  nav.innerHTML = links.map(function (link) {
    var attrs = link.active ? ' aria-current="page" class="is-active"' : '';
    var badge = link.badge > 0 ? '<span class="nav-badge" aria-label="' + link.badge + ' itens no carrinho" style="position:absolute;top:2px;right:18px;min-width:16px;height:16px;padding:0 4px;border-radius:999px;background:#ef4444;color:#fff;font-size:10px;line-height:16px;font-weight:900;box-shadow:0 8px 16px rgba(239,68,68,.28);">' + link.badge + '</span>' : '';
    return '<a href="' + link.href + '" style="position:relative;"' + attrs + ' data-mobile-nav="' + link.label.toLowerCase().replace(/\s+/g, '-') + '"><span class="nav-icon" aria-hidden="true">' + link.icon + '</span><span>' + link.label + '</span>' + badge + '</a>';
  }).join('');

  nav.querySelectorAll('a[data-mobile-nav="liz/ajuda"]').forEach(function (link) {
    link.addEventListener('click', function (event) {
      var launcher = document.getElementById('sv-liz-launcher');
      if (!launcher) {
        return;
      }
      event.preventDefault();
      event.stopPropagation();
      launcher.click();
    });
  });
}
function updatePageState() {
  var body = document.body;
  if (!body) return;
  var path = window.location.pathname || '/';
  var isProduct = /^\/produto(?:\/|$)/i.test(path);
  var isCatalog = /^\/catalogo(?:\/|$)/i.test(path);
  var isHome = isHomePath(path);
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
  installHomeCategoryImages();
  installTestimonials();
  installSupport();
  scheduleResponsivePass();
  window.addEventListener('shopvivaliz:cart-updated', scheduleResponsivePass);
  window.addEventListener('storage', function (event) {
    if (event && event.key === 'shopvivaliz_cart') scheduleResponsivePass();
  });
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

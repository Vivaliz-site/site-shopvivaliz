/**
 * Liz identity and mobile floating-channel alignment.
 *
 * Keeps the existing conversation engine untouched while correcting the visual
 * identity in the launcher, dialog header and bot messages. The portrait crop
 * intentionally focuses on Liz's face instead of the sign/body shown by the
 * source illustration.
 */
(function () {
  'use strict';

  var AVATAR = '/public/assets/liz-assistant/liz-avatar.png';
  var BRAND_FALLBACK = '/images/logo-vivaliz-square-v2.png';
  var STYLE_ID = 'sv-liz-identity-v2-style';
  var OBSERVER_TIMEOUT_MS = 15000;
  var observer = null;
  var stopTimer = null;

  function installStyles() {
    if (document.getElementById(STYLE_ID)) return;
    var style = document.createElement('style');
    style.id = STYLE_ID;
    style.textContent = [
      ':root{--sv-floating-channel-size:56px;--sv-floating-channel-bottom:calc(96px + env(safe-area-inset-bottom,0px))}',
      '#sv-liz-launcher.sv-liz-portrait-v2{overflow:hidden!important;border:3px solid #fff!important;background:linear-gradient(145deg,#e9f8f4,#fff)!important;box-shadow:0 10px 28px rgba(5,42,72,.28),0 0 0 2px rgba(20,184,166,.72)!important}',
      '#sv-liz-launcher.sv-liz-portrait-v2>img{position:absolute!important;left:50%!important;top:50%!important;width:138%!important;height:138%!important;max-width:none!important;max-height:none!important;object-fit:cover!important;object-position:50% 12%!important;transform:translate(-50%,-45%)!important;border-radius:0!important}',
      '#sv-liz-launcher.sv-liz-portrait-v2>img.sv-liz-brand-fallback{width:82%!important;height:82%!important;object-fit:contain!important;object-position:center!important;transform:translate(-50%,-50%)!important}',
      '#sv-liz-launcher.sv-liz-portrait-v2::after{content:"Liz";position:absolute;left:50%;bottom:1px;z-index:2;transform:translateX(-50%);min-width:30px;padding:2px 6px;border-radius:999px;background:rgba(5,42,72,.88);color:#fff;font-size:9px;font-weight:900;line-height:1.1;letter-spacing:.03em;text-transform:uppercase;pointer-events:none}',
      '#sv-liz-launcher.sv-liz-portrait-v2:focus-visible{outline:3px solid rgba(20,184,166,.48)!important;outline-offset:4px!important}',
      '#sv-liz-panel .sv-head.sv-liz-head-v2{display:grid!important;grid-template-columns:44px minmax(0,1fr) auto auto!important;align-items:center!important;gap:9px!important;min-height:62px!important;padding:9px 11px!important}',
      '#sv-liz-panel .sv-head-avatar-v2{position:relative;display:block;width:42px;height:42px;overflow:hidden;border:2px solid rgba(255,255,255,.92);border-radius:50%;background:#fff;box-shadow:0 3px 12px rgba(5,42,72,.18)}',
      '#sv-liz-panel .sv-head-avatar-v2>img{position:absolute;left:50%;top:50%;width:142%;height:142%;max-width:none;object-fit:cover;object-position:50% 12%;transform:translate(-50%,-44%)}',
      '#sv-liz-panel .sv-head-avatar-v2>img.sv-liz-brand-fallback{width:82%;height:82%;object-fit:contain;object-position:center;transform:translate(-50%,-50%)}',
      '#sv-liz-panel .sv-head-avatar-v2::after{content:"";position:absolute;right:0;bottom:1px;width:10px;height:10px;border:2px solid #fff;border-radius:50%;background:#22c55e}',
      '#sv-liz-panel .sv-head-info-v2{display:grid;gap:1px;min-width:0;color:#fff}',
      '#sv-liz-panel .sv-head-info-v2 strong{margin:0!important;color:#fff!important;font-size:15px!important;font-weight:900!important;line-height:1.15!important;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}',
      '#sv-liz-panel .sv-head-info-v2 span{color:rgba(255,255,255,.83);font-size:10px;font-weight:650;line-height:1.2;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}',
      '#sv-liz-panel .sv-head-brand-v2{display:block!important;width:58px!important;height:24px!important;max-width:58px!important;object-fit:contain!important;filter:brightness(0) invert(1);opacity:.9}',
      '#sv-liz-panel .sv-close{min-width:38px!important;min-height:38px!important;margin:0!important}',
      '#sv-liz-panel .sv-hero video{background:#eff9f6}',
      '#sv-liz-panel .sv-hero-fallback-v2{display:block;width:84px;height:84px;margin:0 auto;overflow:hidden;border-radius:50%;object-fit:cover;object-position:50% 12%;transform:scale(1.08)}',
      '#sv-liz-panel .sv-msg.sv-bot{position:relative;margin-left:34px}',
      '#sv-liz-panel .sv-msg.sv-bot::before{content:"";position:absolute;left:-34px;top:2px;width:27px;height:27px;border:2px solid #fff;border-radius:50%;background:#e9f8f4 url("' + AVATAR + '") 50% 10%/148% auto no-repeat;box-shadow:0 2px 8px rgba(5,42,72,.16)}',
      'body.sv-liz-panel-open .sv-support-dock{opacity:0!important;visibility:hidden!important;pointer-events:none!important;transform:translateY(8px)!important}',
      'body.sv-liz-panel-open #sv-liz-bubble{opacity:0!important;visibility:hidden!important;pointer-events:none!important}',
      '.sv-support-dock{transition:opacity .18s ease,transform .18s ease,visibility .18s ease}',
      '@media(max-width:820px){body.sv-has-mobile-bottom-nav #sv-liz-launcher,body.sv-has-mobile-bottom-nav .sv-support-dock{bottom:var(--sv-floating-channel-bottom)!important}body.sv-has-mobile-bottom-nav #sv-liz-launcher{right:max(16px,env(safe-area-inset-right,0px))!important;left:auto!important}body.sv-has-mobile-bottom-nav .sv-support-dock{left:max(16px,env(safe-area-inset-left,0px))!important;right:auto!important}#sv-liz-launcher.sv-liz-portrait-v2,.sv-support-dock .sv-support-button,.sv-support-dock>a,.sv-support-dock>button{width:var(--sv-floating-channel-size)!important;height:var(--sv-floating-channel-size)!important;min-width:var(--sv-floating-channel-size)!important;min-height:var(--sv-floating-channel-size)!important}#sv-liz-bubble{right:14px!important;bottom:calc(var(--sv-floating-channel-bottom) + var(--sv-floating-channel-size) + 10px)!important;max-width:min(245px,calc(100vw - 28px))!important}#sv-liz-panel .sv-head.sv-liz-head-v2{grid-template-columns:42px minmax(0,1fr) auto!important}#sv-liz-panel .sv-head-brand-v2{display:none!important}}',
      'body:has(.sv-mobile-buybar) #sv-liz-launcher,body:has(.sv-mobile-buybar) .sv-support-dock,body:has(.sv-checkout-mobile-total) #sv-liz-launcher,body:has(.sv-checkout-mobile-total) .sv-support-dock{bottom:calc(122px + env(safe-area-inset-bottom,0px))!important}',
      '@media(prefers-reduced-motion:reduce){.sv-support-dock,#sv-liz-launcher,#sv-liz-panel{transition:none!important}}'
    ].join('');
    document.head.appendChild(style);
  }

  function setImageFallback(image) {
    if (!image || image.dataset.svLizFallbackBound === '1') return;
    image.dataset.svLizFallbackBound = '1';
    var applyFallback = function () {
      if (image.dataset.svLizFallbackApplied === '1') return;
      image.dataset.svLizFallbackApplied = '1';
      image.classList.add('sv-liz-brand-fallback');
      image.src = BRAND_FALLBACK;
      image.alt = 'Vivaliz';
    };
    image.addEventListener('error', applyFallback);
    if (image.complete && image.naturalWidth === 0) window.setTimeout(applyFallback, 0);
  }

  function enhanceLauncher(root) {
    var launcher = root.querySelector('#sv-liz-launcher');
    if (!launcher) return;
    launcher.classList.add('sv-liz-portrait-v2');
    launcher.setAttribute('aria-label', 'Abrir conversa com a Liz, assistente virtual da ShopVivaliz');
    launcher.title = 'Fale com a Liz';
    var image = launcher.querySelector('img');
    if (image) {
      image.src = AVATAR;
      image.alt = '';
      image.setAttribute('aria-hidden', 'true');
      image.setAttribute('draggable', 'false');
      image.decoding = 'async';
      setImageFallback(image);
    }
    var bubble = root.querySelector('#sv-liz-bubble');
    if (bubble) bubble.textContent = 'Oi! Sou a Liz. Posso ajudar com sua compra?';
  }

  function enhanceHeader(root) {
    var panel = root.querySelector('#sv-liz-panel');
    var head = panel && panel.querySelector('.sv-head');
    if (!panel || !head || head.dataset.svLizIdentityV2 === '1') return;
    head.dataset.svLizIdentityV2 = '1';
    head.classList.add('sv-liz-head-v2');

    var close = head.querySelector('.sv-close');
    var oldImage = head.querySelector(':scope > img');
    var oldTitle = head.querySelector(':scope > strong');
    if (oldImage) oldImage.remove();
    if (oldTitle) oldTitle.remove();

    var avatar = document.createElement('span');
    avatar.className = 'sv-head-avatar-v2';
    avatar.setAttribute('aria-hidden', 'true');
    var avatarImage = document.createElement('img');
    avatarImage.src = AVATAR;
    avatarImage.alt = '';
    avatarImage.width = 84;
    avatarImage.height = 84;
    avatarImage.decoding = 'async';
    avatar.appendChild(avatarImage);
    setImageFallback(avatarImage);

    var info = document.createElement('span');
    info.className = 'sv-head-info-v2';
    var name = document.createElement('strong');
    name.textContent = 'Liz';
    var role = document.createElement('span');
    role.textContent = 'Assistente virtual da ShopVivaliz';
    info.append(name, role);

    var brand = document.createElement('img');
    brand.className = 'sv-head-brand-v2';
    brand.src = '/public/assets/liz-assistant/logo-oficial.svg';
    brand.alt = 'ShopVivaliz';
    brand.width = 58;
    brand.height = 24;
    brand.loading = 'lazy';
    brand.decoding = 'async';

    if (close) {
      head.insertBefore(avatar, close);
      head.insertBefore(info, close);
      head.insertBefore(brand, close);
    } else {
      head.append(avatar, info, brand);
    }

    panel.setAttribute('aria-label', 'Conversa com Liz, assistente virtual da ShopVivaliz');
    var video = panel.querySelector('.sv-hero video');
    if (video) {
      video.poster = AVATAR;
      video.setAttribute('aria-hidden', 'true');
      video.addEventListener('error', function () {
        if (video.dataset.svLizFallbackApplied === '1') return;
        video.dataset.svLizFallbackApplied = '1';
        video.hidden = true;
        var fallback = document.createElement('img');
        fallback.className = 'sv-hero-fallback-v2';
        fallback.src = AVATAR;
        fallback.alt = 'Liz, assistente virtual da ShopVivaliz';
        fallback.width = 84;
        fallback.height = 84;
        video.parentNode.appendChild(fallback);
        setImageFallback(fallback);
      });
    }
  }

  function syncOpenAndMobileState(root) {
    var panel = root.querySelector('#sv-liz-panel');
    if (!panel) return;
    var sync = function () {
      document.body.classList.toggle('sv-liz-panel-open', panel.classList.contains('open'));
      document.body.classList.toggle('sv-has-mobile-bottom-nav', Boolean(document.querySelector('.sv-mobile-nav-bar,.sv-mobile-bottom-nav')));
    };
    sync();
    if (panel.dataset.svLizOpenObserver !== '1') {
      panel.dataset.svLizOpenObserver = '1';
      new MutationObserver(sync).observe(panel, { attributes: true, attributeFilter: ['class'] });
      window.addEventListener('resize', sync, { passive: true });
    }
  }

  function enhanceBotMessages(root) {
    var messages = root.querySelector('.sv-msgs');
    if (!messages || messages.dataset.svLizMessageObserver === '1') return;
    messages.dataset.svLizMessageObserver = '1';
    messages.querySelectorAll('.sv-msg.sv-bot').forEach(function (message) {
      message.setAttribute('data-liz-speaker', 'Liz');
    });
    new MutationObserver(function (records) {
      records.forEach(function (record) {
        Array.prototype.forEach.call(record.addedNodes, function (node) {
          if (!(node instanceof HTMLElement)) return;
          if (node.matches('.sv-msg.sv-bot')) node.setAttribute('data-liz-speaker', 'Liz');
          if (node.querySelectorAll) {
            node.querySelectorAll('.sv-msg.sv-bot').forEach(function (message) {
              message.setAttribute('data-liz-speaker', 'Liz');
            });
          }
        });
      });
    }).observe(messages, { childList: true, subtree: true });
  }

  function enhance() {
    var launcher = document.getElementById('sv-liz-launcher');
    if (!launcher) return false;
    var root = launcher.parentElement;
    if (!root) return false;
    installStyles();
    enhanceLauncher(root);
    enhanceHeader(root);
    syncOpenAndMobileState(root);
    enhanceBotMessages(root);
    root.dataset.svLizIdentity = 'portrait-v2';
    return true;
  }

  installStyles();
  if (!enhance()) {
    observer = new MutationObserver(function () {
      if (!enhance()) return;
      if (observer) observer.disconnect();
      if (stopTimer) window.clearTimeout(stopTimer);
    });
    observer.observe(document.documentElement, { childList: true, subtree: true });
    stopTimer = window.setTimeout(function () {
      if (observer) observer.disconnect();
    }, OBSERVER_TIMEOUT_MS);
  }
})();
(function () {
  'use strict';

  if (window.__svLizIdentityFixStarted) return;
  window.__svLizIdentityFixStarted = true;

  var AVATAR = '/public/assets/liz-assistant/liz-avatar.png';
  var observer = null;
  var timeout = null;

  function makeAvatar(className) {
    var image = document.createElement('img');
    image.className = className || '';
    image.src = AVATAR;
    image.alt = '';
    image.setAttribute('aria-hidden', 'true');
    image.decoding = 'async';
    return image;
  }

  function enhanceLauncher() {
    var launcher = document.getElementById('sv-liz-launcher');
    if (!launcher) return;
    var image = launcher.querySelector('img');
    if (!image) {
      image = makeAvatar('sv-liz-launcher-avatar');
      launcher.appendChild(image);
    }
    image.src = AVATAR;
    image.alt = '';
    image.setAttribute('aria-hidden', 'true');
    image.classList.add('sv-liz-launcher-avatar');
    launcher.title = 'Abrir atendimento com a Liz';
  }

  function enhanceHeader(panel) {
    var head = panel.querySelector('.sv-head');
    if (!head || head.dataset.svLizIdentity === 'ready') return;

    Array.prototype.slice.call(head.children).forEach(function (child) {
      if (child.tagName === 'IMG' || child.tagName === 'STRONG') child.remove();
    });

    var close = head.querySelector('.sv-close');
    var avatarWrapper = document.createElement('span');
    avatarWrapper.className = 'sv-head-avatar-wrapper';
    avatarWrapper.appendChild(makeAvatar('sv-head-avatar'));

    var online = document.createElement('span');
    online.className = 'sv-online-badge';
    online.setAttribute('aria-hidden', 'true');
    avatarWrapper.appendChild(online);

    var info = document.createElement('span');
    info.className = 'sv-head-info';
    var name = document.createElement('strong');
    name.textContent = 'Liz';
    var status = document.createElement('span');
    status.className = 'sv-head-status';
    status.textContent = 'Assistente virtual da ShopVivaliz';
    info.append(name, status);

    head.insertBefore(avatarWrapper, close || null);
    head.insertBefore(info, close || null);
    head.dataset.svLizIdentity = 'ready';
  }

  function enhanceHero(panel) {
    var hero = panel.querySelector('.sv-hero');
    var video = hero && hero.querySelector('video');
    if (!hero || hero.dataset.svLizFallback === 'ready') return;

    var fallback = hero.querySelector('.sv-hero-fallback');
    if (!fallback) {
      fallback = makeAvatar('sv-hero-fallback');
      hero.insertBefore(fallback, video || null);
    }

    if (video) {
      video.poster = AVATAR;
      var showVideo = function () { hero.dataset.svVideoReady = 'true'; };
      var showFallback = function () { hero.dataset.svVideoReady = 'false'; };
      video.addEventListener('loadeddata', showVideo);
      video.addEventListener('playing', showVideo);
      video.addEventListener('error', showFallback);
      video.addEventListener('abort', showFallback);
      video.addEventListener('stalled', showFallback);
      if (video.readyState >= 2) showVideo();
      else showFallback();
    } else {
      hero.dataset.svVideoReady = 'false';
    }

    hero.dataset.svLizFallback = 'ready';
  }

  function enhance() {
    enhanceLauncher();
    var panel = document.getElementById('sv-liz-panel');
    if (!panel) return false;
    enhanceHeader(panel);
    enhanceHero(panel);
    panel.dataset.svLizIdentity = 'ready';
    return true;
  }

  function stopWatching() {
    if (observer) observer.disconnect();
    observer = null;
    if (timeout) window.clearTimeout(timeout);
    timeout = null;
  }

  function boot() {
    if (enhance()) return;
    observer = new MutationObserver(function () {
      if (enhance()) stopWatching();
    });
    observer.observe(document.documentElement, { childList: true, subtree: true });
    timeout = window.setTimeout(stopWatching, 15000);
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot, { once: true });
  else boot();
}());

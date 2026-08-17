(() => {
  'use strict';

  const MASCOT_AVATAR = '/public/assets/liz-assistant/liz-avatar.png';
  const MASCOT_VIDEO = '/public/assets/liz-assistant/liz-acenando.webm';

  function restoreMascotHero(hero) {
    let video = hero.querySelector('video');
    if (!video) {
      hero.replaceChildren();
      video = document.createElement('video');
      video.muted = true;
      video.loop = true;
      video.playsInline = true;
      video.preload = 'none';
      video.dataset.src = MASCOT_VIDEO;
      hero.appendChild(video);
    }

    const panel = hero.closest('#sv-liz-panel');
    if (panel && panel.classList.contains('open') && !video.getAttribute('src')) {
      video.src = MASCOT_VIDEO;
      video.load();
      const play = video.play();
      if (play && typeof play.catch === 'function') play.catch(() => {});
    }
  }

  function applyCorrections() {
    const launcher = document.getElementById('sv-liz-launcher');
    if (launcher) {
      let label = launcher.querySelector('.sv-liz-launcher-label');
      if (!label) {
        label = document.createElement('span');
        label.className = 'sv-liz-launcher-label';
        label.setAttribute('aria-hidden', 'true');
        launcher.prepend(label);
      }
      label.textContent = 'Dúvidas? Fale com a Liz';
      const image = launcher.querySelector('img');
      if (image) {
        if (image.getAttribute('src') !== MASCOT_AVATAR) image.src = MASCOT_AVATAR;
        image.alt = 'Liz, assistente virtual da ShopVivaliz';
        image.width = 96;
        image.height = 96;
        delete image.dataset.svOfficialBrand;
      }
      launcher.setAttribute('aria-label', 'Abrir atendimento com a Liz');
      launcher.style.setProperty('position', 'fixed', 'important');
      launcher.style.setProperty('top', 'auto', 'important');
      launcher.style.setProperty('left', 'auto', 'important');
      launcher.style.setProperty('right', 'calc(18px + env(safe-area-inset-right, 0px))', 'important');
      launcher.style.setProperty('bottom', 'calc(18px + var(--sv-bottom-ui-offset, 0px) + env(safe-area-inset-bottom, 0px))', 'important');
      launcher.style.setProperty('opacity', '1', 'important');
      launcher.style.setProperty('visibility', 'visible', 'important');
      launcher.style.setProperty('pointer-events', 'auto', 'important');
      launcher.style.setProperty('transform', 'none', 'important');
    }

    const hero = document.querySelector('#sv-liz-panel .sv-hero');
    if (hero) restoreMascotHero(hero);

    return Boolean(launcher && hero);
  }

  if (applyCorrections()) return;

  const observer = new MutationObserver(() => {
    if (applyCorrections()) observer.disconnect();
  });
  observer.observe(document.documentElement, { childList: true, subtree: true });
  window.setTimeout(() => observer.disconnect(), 15000);
})();

<?php
declare(strict_types=1);
?>
<meta name="referrer" content="strict-origin-when-cross-origin">
<script>
(function () {
  'use strict';

  if (window.__svYoutubeEmbedFix153) {
    return;
  }
  window.__svYoutubeEmbedFix153 = true;

  document.addEventListener('click', function (event) {
    var target = event.target instanceof Element ? event.target : null;
    var button = target ? target.closest('.thumb-btn[data-type="video"]') : null;
    if (!button) {
      return;
    }

    var container = document.getElementById('product-zoom-box');
    var mainImage = document.getElementById('main-product-image');
    var source = button.getAttribute('data-src') || '';
    if (!container || !source) {
      return;
    }

    // Intercepta apenas o clique da miniatura de video antes do manipulador
    // legado, para que o iframe ja nasca com identificacao de referenciador.
    event.preventDefault();
    event.stopPropagation();

    document.querySelectorAll('.thumb-btn').forEach(function (thumb) {
      thumb.classList.remove('active');
      thumb.style.borderColor = '#e2e8f0';
    });
    button.classList.add('active');
    button.style.borderColor = '#0b4f88';

    try {
      var embedUrl = new URL(source, window.location.href);
      var host = embedUrl.hostname.toLowerCase();
      if (
        host === 'youtube.com' ||
        host === 'www.youtube.com' ||
        host === 'youtube-nocookie.com' ||
        host === 'www.youtube-nocookie.com'
      ) {
        embedUrl.searchParams.set('playsinline', '1');
        embedUrl.searchParams.set('origin', window.location.origin);
        source = embedUrl.toString();
      }
    } catch (error) {
      // Mantem a URL original caso o navegador nao consiga normaliza-la.
    }

    var iframe = document.getElementById('main-product-video');
    if (!iframe) {
      iframe = document.createElement('iframe');
      iframe.id = 'main-product-video';
      iframe.title = 'Vídeo do produto';
      iframe.referrerPolicy = 'strict-origin-when-cross-origin';
      iframe.allow = 'accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share';
      iframe.allowFullscreen = true;
      iframe.style.width = '100%';
      iframe.style.height = '100%';
      iframe.style.border = 'none';
      iframe.style.position = 'absolute';
      iframe.style.top = '0';
      iframe.style.left = '0';
      iframe.style.zIndex = '10';
      container.appendChild(iframe);
    } else {
      iframe.referrerPolicy = 'strict-origin-when-cross-origin';
    }

    iframe.src = source;
    iframe.style.display = 'block';
    if (mainImage) {
      mainImage.style.display = 'none';
    }
  }, true);
})();
</script>

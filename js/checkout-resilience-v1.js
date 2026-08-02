(function () {
  'use strict';
  if (window.__svCheckoutResilienceReady) return;
  window.__svCheckoutResilienceReady = true;

  var nativeFetch = window.fetch.bind(window);
  var lastCep = '';
  var shippingRequests = 0;

  function requestUrl(input) {
    if (typeof input === 'string') return input;
    return input && typeof input.url === 'string' ? input.url : '';
  }

  function setShippingBusy(busy) {
    var submit = document.getElementById('submit-btn');
    var status = document.getElementById('checkout-shipping-status');
    if (submit) submit.disabled = Boolean(busy);
    if (status) status.setAttribute('aria-busy', busy ? 'true' : 'false');
  }

  window.fetch = function (input, init) {
    var url = requestUrl(input);
    var isShipping = url.indexOf('/api/melhorenvio/shipping-check-v2.php') !== -1;
    var options = Object.assign({}, init || {});
    var controller = null;
    var timeoutId = null;

    if (isShipping && !options.signal && typeof AbortController !== 'undefined') {
      controller = new AbortController();
      options.signal = controller.signal;
      timeoutId = window.setTimeout(function () { controller.abort(); }, 12000);
      shippingRequests += 1;
      setShippingBusy(true);
    }

    return nativeFetch(input, options).then(function (response) {
      if (response.status === 401 || response.status === 419) {
        window.dispatchEvent(new CustomEvent('sv:session-expired', { detail: { url: url, status: response.status } }));
      }
      return response;
    }).finally(function () {
      if (timeoutId !== null) window.clearTimeout(timeoutId);
      if (controller) {
        shippingRequests = Math.max(0, shippingRequests - 1);
        setShippingBusy(shippingRequests > 0);
      }
    });
  };

  function currentCep() {
    var input = document.getElementById('cep-input');
    return input ? String(input.value || '').replace(/\D/g, '') : lastCep;
  }

  function installRetry() {
    var status = document.getElementById('checkout-shipping-status');
    if (!status || status.hidden) return;
    var text = String(status.textContent || '');
    if (!/(Falha de conexão|temporariamente indisponível|Não foi possível|demorou mais)/i.test(text)) return;
    if (status.querySelector('[data-sv-shipping-retry]')) return;

    var wrapper = document.createElement('span');
    wrapper.className = 'sv-shipping-recovery';
    wrapper.innerHTML = ' <button type="button" data-sv-shipping-retry>Tentar novamente</button> <a href="/carrinho">Voltar ao carrinho</a>';
    status.appendChild(wrapper);

    var retry = wrapper.querySelector('[data-sv-shipping-retry]');
    if (retry) {
      retry.addEventListener('click', function () {
        var cep = currentCep();
        var input = document.getElementById('cep-input');
        if (!input || cep.length !== 8) return;
        input.dispatchEvent(new Event('blur', { bubbles: true }));
      });
    }
  }

  function init() {
    var cep = document.getElementById('cep-input');
    if (cep) {
      cep.addEventListener('input', function () { lastCep = currentCep(); }, { passive: true });
    }

    var status = document.getElementById('checkout-shipping-status');
    if (status && typeof MutationObserver !== 'undefined') {
      new MutationObserver(installRetry).observe(status, {
        childList: true,
        subtree: true,
        characterData: true,
        attributes: true,
        attributeFilter: ['hidden']
      });
    }

    window.addEventListener('sv:session-expired', function () {
      var checkoutStatus = document.getElementById('checkout-status');
      if (!checkoutStatus) return;
      checkoutStatus.className = 'checkout-status-msg err';
      checkoutStatus.innerHTML = 'Sua sessão expirou, mas o carrinho foi preservado. <a href="/auth/login.php?redirect=%2Fcheckout">Entre novamente</a> para continuar.';
    });
  }

  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', init);
  else init();
})();

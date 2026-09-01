(function () {
  'use strict';

  var path = String(window.location.pathname || '').replace(/\/$/, '');
  if (path !== '/checkout' || typeof window.fetch !== 'function') return;

  var originalFetch = window.fetch;
  if (originalFetch.__svShippingRequestDedupeWrapped) return;

  var inFlightKey = '';
  var inFlightPromise = null;

  function requestKey(resource, init) {
    var url = typeof resource === 'string'
      ? resource
      : (resource && resource.url ? String(resource.url) : '');
    if (url.indexOf('/api/melhorenvio/shipping-check-v2.php') === -1) return '';

    var method = String((init && init.method) || 'GET').toUpperCase();
    var body = init && typeof init.body === 'string' ? init.body : '';
    return method + '\n' + url + '\n' + body;
  }

  function wrappedFetch(resource, init) {
    var key = requestKey(resource, init);
    if (!key) return originalFetch.apply(this, arguments);

    if (inFlightPromise && inFlightKey === key) {
      return inFlightPromise.then(function (response) {
        return response.clone();
      });
    }

    var request = originalFetch.apply(this, arguments);
    inFlightKey = key;
    inFlightPromise = request;

    function clear() {
      if (inFlightPromise === request) {
        inFlightKey = '';
        inFlightPromise = null;
      }
    }

    request.then(clear, clear);
    return request;
  }

  wrappedFetch.__svShippingRequestDedupeWrapped = true;
  wrappedFetch.__svShippingRequestDedupeOriginal = originalFetch;
  window.fetch = wrappedFetch;
})();

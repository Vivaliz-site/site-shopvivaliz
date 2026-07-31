(() => {
  const endpoint = '/api/ml/event.php';
  const productFrom = (node) => {
    const tagged = node?.closest?.('[data-product-id],[data-sku]');
    if (tagged) return tagged.dataset.productId || tagged.dataset.sku || '';
    const link = node?.closest?.('a[href*="/produto/"]');
    return link ? decodeURIComponent(link.getAttribute('href').split('/produto/')[1]?.split(/[?#]/)[0] || '') : '';
  };
  const send = (eventType, productId) => {
    if (!productId) return;
    const body = JSON.stringify({event_type: eventType, product_id: productId, path: location.pathname});
    if (navigator.sendBeacon) navigator.sendBeacon(endpoint, new Blob([body], {type: 'application/json'}));
    else fetch(endpoint, {method: 'POST', headers: {'Content-Type': 'application/json'}, body, keepalive: true}).catch(() => {});
  };
  const current = location.pathname.match(/^\/produto\/([^/?#]+)/);
  if (current) send('page_view', decodeURIComponent(current[1]));
  document.addEventListener('click', (event) => {
    const id = productFrom(event.target);
    if (!id) return;
    send(event.target.closest('button,[data-add-to-cart]') ? 'add_to_cart' : 'click', id);
  }, {capture: true});
  window.addEventListener('shopvivaliz:add_to_cart', (event) => send('add_to_cart', String(event.detail?.product_id || '')));
  window.addEventListener('shopvivaliz:purchase', (event) => send('purchase', String(event.detail?.product_id || '')));
})();

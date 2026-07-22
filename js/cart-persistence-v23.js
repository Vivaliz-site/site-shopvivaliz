(function () {
  var key = 'shopvivaliz_cart';

  function normalize(item) {
    var sku = String(item && item.sku ? item.sku : '').trim();
    var name = String(item && item.name ? item.name : sku).trim();
    if (!sku || !name) return null;
    return {
      sku: sku,
      name: name,
      image_url: String(item.image_url || ''),
      price: Math.max(0, Number(item.price || 0)),
      quantity: Math.max(1, Math.min(99, parseInt(item.quantity || 1, 10) || 1)),
      olist_product_id: String(item.olist_product_id || '').trim()
    };
  }

  function read() {
    try {
      var parsed = JSON.parse(localStorage.getItem(key) || '[]');
      return Array.isArray(parsed) ? parsed.map(normalize).filter(Boolean) : [];
    } catch (error) {
      return [];
    }
  }

  function write(items) {
    var normalized = (Array.isArray(items) ? items : []).map(normalize).filter(Boolean);
    localStorage.setItem(key, JSON.stringify(normalized));
    localStorage.setItem('shopvivaliz_cart_updated_at', String(Date.now()));
    window.dispatchEvent(new CustomEvent('shopvivaliz:cart-updated', { detail: { items: normalized } }));
    return normalized;
  }

  function add(item) {
    var product = normalize(item);
    if (!product) return read();
    var items = read();
    var existing = items.find(function (candidate) { return candidate.sku === product.sku; });
    if (existing) {
      existing.quantity = Math.min(99, existing.quantity + product.quantity);
    } else {
      items.push(product);
    }
    return write(items);
  }

  window.ShopVivalizCart = {
    get: read,
    set: write,
    add: add,
    clear: function () {
      write([]);
      localStorage.removeItem('shopvivaliz_shipping_quote');
    },
    count: function () {
      return read().reduce(function (sum, item) { return sum + item.quantity; }, 0);
    }
  };

  window.addEventListener('storage', function (event) {
    if (event.key === key) {
      window.dispatchEvent(new CustomEvent('shopvivaliz:cart-updated', { detail: { items: read() } }));
    }
  });
})();

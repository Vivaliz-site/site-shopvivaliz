(function () {
  window.dataLayer = window.dataLayer || [];
  window.ShopVivalizEvents = window.ShopVivalizEvents || {
    track: function (name, payload) {
      window.dataLayer.push({ event: name, ecommerce: payload || {} });
    }
  };
})();

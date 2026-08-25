(function () {
    var detail = document.querySelector('.product-detail');
    if (!detail) return;

    document.body.classList.add('sv-page-product');
    var context = window.ShopVivalizProductContext || {};
    var imageBox = detail.querySelector('.product-detail-image');
    var image = imageBox && imageBox.querySelector('img');
    var buy = document.getElementById('buy-now');
    var skuLine = detail.querySelector('.product-sku-line');

    function digits(v) {
        return String(v || '').replace(/\D/g, '').slice(0, 8);
    }

    function formatCep(v) {
        return v.length > 5 ? v.slice(0, 5) + '-' + v.slice(5) : v;
    }

    function formatMoney(v) {
        return Number(v || 0).toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' });
    }

    function svPcv5Escape(v) {
        return String(v == null ? '' : v).replace(/[&<>"']/g, function (c) {
            return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c];
        });
    }

    function formatDelivery(option) {
        var parts = [];
        if (option.company) parts.push(option.company);
        if (option.name) parts.push(option.name);
        var header = svPcv5Escape(parts.join(' - ') || 'Frete');
        var price = Number(option.price || 0);
        var days = option.delivery_time || option.delivery_days || '';
        return '<strong>' + header + '</strong><br>' + (price > 0 ? formatMoney(price) : 'Frete a confirmar') + (days ? ' • prazo estimado de ' + svPcv5Escape(days) + ' dia(s)' : '');
    }

    function shippingErrorMessage(data) {
        if (!data || typeof data !== 'object') return 'Não foi possível calcular o frete para este CEP.';
        if (data.error === 'invalid_shipping_destination' || data.error === 'invalid_cep') return 'CEP inválido. Confira os 8 números e tente novamente.';
        return data.message || 'Não foi possível calcular o frete para este CEP.';
    }

    function qty() {
        var input = document.getElementById('product-qty-input') || document.querySelector('.sv-qty input');
        return Math.max(1, Math.min(99, parseInt(input && input.value, 10) || 1));
    }

    // Handle quantity buttons
    var qtyMinus = document.getElementById('qty-minus');
    var qtyPlus = document.getElementById('qty-plus');
    var qtyInput = document.getElementById('product-qty-input');

    if (qtyMinus && qtyInput) {
        qtyMinus.addEventListener('click', function () {
            var cur = qty();
            if (cur > 1) qtyInput.value = String(cur - 1);
        });
    }
    if (qtyPlus && qtyInput) {
        qtyPlus.addEventListener('click', function () {
            var cur = qty();
            if (cur < 99) qtyInput.value = String(cur + 1);
        });
    }

    function cartProduct() {
        var sku = (skuLine ? skuLine.textContent.replace(/^SKU:\s*/i, '').trim() : '') || String(context.sku || '');
        var title = detail.querySelector('h1');
        var numericPrice = typeof context.price === 'number' && context.price > 0 ? context.price : 0;
        return {
            sku: sku,
            name: title ? title.textContent.trim() : (context.name || 'Produto Vivaliz'),
            image_url: image ? image.src : '',
            price: numericPrice,
            quantity: qty(),
            olist_product_id: String(context.olist_product_id || detail.getAttribute('data-product-id') || sku || '')
        };
    }

    if (buy) {
        buy.addEventListener('click', function (e) {
            e.preventDefault();
            e.stopImmediatePropagation();
            var product = cartProduct(), items = [];
            try {
                items = JSON.parse(localStorage.getItem('shopvivaliz_cart') || '[]');
            } catch (err) { }
            var existing = items.find(function (item) {
                return item.sku === product.sku;
            });
            if (existing) {
                existing.quantity = (existing.quantity || 1) + product.quantity;
            } else {
                items.push(product);
            }
            localStorage.setItem('shopvivaliz_cart', JSON.stringify(items));
            window.dispatchEvent(new CustomEvent('shopvivaliz:add_to_cart', {
                detail: { product_id: product.olist_product_id, sku: product.sku, quantity: product.quantity }
            }));
            window.location.href = '/carrinho';
        }, true);
    }

    // Interactive Image Modal
    if (imageBox && image) {
        var modal = document.createElement('div');
        modal.className = 'sv-image-modal';
        modal.hidden = true;
        modal.innerHTML = '<button type="button" aria-label="Fechar">×</button><img alt="">';
        document.body.appendChild(modal);

        function openModal() {
            modal.querySelector('img').src = image.src;
            modal.querySelector('img').alt = image.alt;
            modal.hidden = false;
            document.body.style.overflow = 'hidden';
        }

        function closeModal() {
            modal.hidden = true;
            document.body.style.overflow = '';
        }

        imageBox.setAttribute('tabindex', '0');
        imageBox.setAttribute('role', 'button');
        imageBox.setAttribute('aria-label', 'Ampliar imagem do produto');
        imageBox.addEventListener('click', function (e) {
            if (e.target.tagName !== 'BUTTON' && !e.target.closest('.product-gallery-thumbnails')) {
                openModal();
            }
        });

        imageBox.addEventListener('keydown', function (e) {
            if (e.key === 'Enter' || e.key === ' ') {
                e.preventDefault();
                openModal();
            }
        });

        modal.addEventListener('click', function (e) {
            if (e.target === modal || e.target.closest('button')) closeModal();
        });

        document.addEventListener('keydown', function (e) {
            if (e.key === 'Escape' && !modal.hidden) closeModal();
        });
    }
})();


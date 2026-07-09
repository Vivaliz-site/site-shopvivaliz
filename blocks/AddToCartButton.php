<?php
declare(strict_types=1);

namespace Blocks;

class AddToCartButton extends BaseBlock {
    protected function getDefaultStyles(): array {
        return [
            'display' => 'inline-block',
            'backgroundColor' => '#059669',
            'color' => '#ffffff',
            'padding' => '14px 32px',
            'borderRadius' => '8px',
            'border' => 'none',
            'fontSize' => '16px',
            'fontWeight' => '600',
            'cursor' => 'pointer',
            'transition' => 'all 0.3s ease',
            'margin' => '20px 0'
        ];
    }

    public function render(): string {
        $text = $this->esc($this->prop('text', 'Adicionar ao Carrinho'));
        $productSku = $this->esc($this->prop('product_sku', ''));
        $productName = $this->esc($this->prop('product_name', ''));
        $price = (float)($this->prop('price', 0));
        $showQuantity = $this->prop('show_quantity', false) ? '1' : '0';

        $hoverStyle = "
            :hover {
                backgroundColor: #047857;
                transform: translateY(-2px);
                boxShadow: 0 8px 16px rgba(5, 150, 105, 0.3);
            }
        ";

        return <<<HTML
<button class="add-to-cart-button"
        style="{$this->styleToString()}"
        data-sku="$productSku"
        data-name="$productName"
        data-price="$price"
        data-show-quantity="$showQuantity"
        onclick="addToCart(this)">
    🛒 $text
</button>

<script>
function addToCart(btn) {
    const sku = btn.dataset.sku;
    const name = btn.dataset.name;
    const price = parseFloat(btn.dataset.price);

    if (!sku) {
        alert('Erro: SKU do produto não configurado');
        return;
    }

    const product = { sku, name, price };

    // Salvar no localStorage
    let cart = JSON.parse(localStorage.getItem('shopvivaliz_cart') || '[]');
    const existing = cart.find(p => p.sku === sku);

    if (existing) {
        existing.quantity = (existing.quantity || 1) + 1;
    } else {
        cart.push({ ...product, quantity: 1 });
    }

    localStorage.setItem('shopvivaliz_cart', JSON.stringify(cart));

    // Enviar signal ao servidor
    fetch('/api/catalog/signal.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ event: 'cart_add', sku, name, price })
    }).catch(e => console.log('Signal failed:', e));

    // Feedback visual
    btn.textContent = '✓ Adicionado!';
    btn.style.backgroundColor = '#10b981';

    setTimeout(() => {
        btn.textContent = '🛒 Adicionar ao Carrinho';
        btn.style.backgroundColor = '#059669';
    }, 2000);
}
</script>

<style>
.add-to-cart-button:hover {
    background-color: #047857 !important;
    transform: translateY(-2px);
    box-shadow: 0 8px 16px rgba(5, 150, 105, 0.3);
}
</style>
HTML;
    }

    public static function getMetadata(): array {
        return [
            'name' => 'Add to Cart Button',
            'description' => 'Botão para adicionar produto ao carrinho',
            'icon' => '🛒',
            'category' => 'Produto',
            'props' => [
                'text' => ['type' => 'text', 'default' => 'Adicionar ao Carrinho'],
                'product_sku' => ['type' => 'text', 'default' => ''],
                'product_name' => ['type' => 'text', 'default' => ''],
                'price' => ['type' => 'number', 'default' => 0],
                'show_quantity' => ['type' => 'boolean', 'default' => false]
            ]
        ];
    }
}

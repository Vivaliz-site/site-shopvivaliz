<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$cart = file_get_contents($root . '/carrinho.php');
$loader = file_get_contents($root . '/includes/load-custom-css.php');
if (!is_string($cart) || $cart === '' || !is_string($loader) || $loader === '') {
    fwrite(STDERR, "FALHOU: fontes do carrinho indisponiveis\n");
    exit(1);
}

function cart_cls_assert(bool $condition, string $message): void
{
    if ($condition) return;
    fwrite(STDERR, "FALHOU: {$message}\n");
    exit(1);
}

cart_cls_assert(
    str_contains($cart, 'class="sv-cart-chrome-slot"'),
    'Carrinho deve reservar um slot estavel para o chrome antes dos estilos tardios.'
);
cart_cls_assert(
    str_contains($cart, '.sv-cart-chrome-slot { min-height: 87px; }'),
    'Desktop deve reservar a altura final medida do navbar.'
);
cart_cls_assert(
    str_contains($cart, '.sv-cart-chrome-slot { min-height: 59px; }'),
    'Mobile deve reservar a altura final medida do navbar.'
);

cart_cls_assert(
    str_contains($cart, 'data-sv-mixed-promo-cart="1"'),
    'Banner promocional do carrinho deve existir no HTML inicial para nao deslocar o layout no DOMContentLoaded.'
);
cart_cls_assert(
    strpos($cart, 'data-sv-mixed-promo-cart="1"') < strpos($cart, 'class="cart-layout"'),
    'Banner promocional deve ser renderizado antes do grid do carrinho.'
);

cart_cls_assert(
    str_contains($cart, 'data-sv-empty-state="initial"'),
    'Carrinho vazio deve existir no HTML inicial, antes do JS de fim da pagina.'
);
cart_cls_assert(
    str_contains($cart, "if (!list.querySelector('[data-sv-empty-state]'))"),
    'Render vazio nao deve reescrever uma arvore que ja esta estavel.'
);
cart_cls_assert(
    str_contains($loader, "--sv-cart-reserved-height"),
    'Prepaint deve reservar altura do carrinho a partir do estado local antes do body.'
);
cart_cls_assert(
    str_contains($cart, 'min-height: var(--sv-cart-reserved-height'),
    'Lista do carrinho deve consumir a reserva calculada no prepaint.'
);
cart_cls_assert(
    str_contains($cart, 'html.sv-cart-page:not(.sv-cart-empty) [data-sv-empty-state="initial"]'),
    'Fallback vazio deve ficar invisivel antes do primeiro paint quando ha itens locais.'
);

fwrite(STDOUT, "COMPROVADO: carrinho possui estado inicial e reserva geometrica antes do primeiro paint.\n");

<?php
declare(strict_types=1);

$cart = file_get_contents(dirname(__DIR__) . '/carrinho.php') ?: '';
$forbidden = [
    'Formas de pagamento aceitas:',
    'PIX disponível',
    'Cartão de crédito</strong> com condições apresentadas pelo gateway',
    'Boleto bancário</strong> disponível pelo Mercado Pago',
];

foreach ($forbidden as $text) {
    if (str_contains($cart, $text)) {
        fwrite(STDERR, "FALHOU: bloco de formas de pagamento ainda existe no carrinho: {$text}\n");
        exit(1);
    }
}

echo "cart-payment-block-removed: ok\n";

<?php
declare(strict_types=1);

function svco_cart_filter(string $html): string
{
    if ($html === '') return $html;

    // O carrinho legado ainda anunciava 5% no PIX e 6x sem juros como regra
    // universal. A condicao real depende do gateway selecionado no checkout e
    // nao deve competir com a promocao automatica de 3% por carrinho misto.
    $updated = preg_replace(
        '~⚡\s*<strong>PIX com 5% de desconto</strong><br>\s*💳\s*<strong>Cartão de Crédito</strong> em até 6x sem juros<br>\s*📄\s*<strong>Boleto Bancário</strong> com confirmação rápida~u',
        '⚡ <strong>PIX disponível</strong><br>\n                    💳 <strong>Cartão de crédito</strong> com parcelamento conforme o gateway escolhido<br>\n                    📄 <strong>Boleto bancário</strong> disponível pelo Mercado Pago',
        $html,
        1
    );

    return is_string($updated) ? $updated : $html;
}

function svco_cart_register(string $requestPath): void
{
    static $registered = false;
    if ($registered || PHP_SAPI === 'cli' || $requestPath !== '/carrinho') return;
    if (!in_array(strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')), ['GET','HEAD'], true)) return;
    $registered = true;
    ob_start('svco_cart_filter');
}

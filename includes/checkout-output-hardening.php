<?php
declare(strict_types=1);

function svcoh_replace_or_original(string $html, string $pattern, string $replacement): string
{
    $updated = @preg_replace($pattern, $replacement, $html, 1);
    return is_string($updated) ? $updated : $html;
}

function svcoh_filter(string $html): string
{
    if ($html === '') {
        return $html;
    }

    // SDKs externos nao devem bloquear o parser da tela principal.
    $html = svcoh_replace_or_original(
        $html,
        '~<script\s+src="https://sdk\.mercadopago\.com/js/v2"\s*></script>~i',
        '<script defer src="https://sdk.mercadopago.com/js/v2"></script>'
    );
    $html = svcoh_replace_or_original(
        $html,
        '~<script\s+src="https://www\.mercadopago\.com/v2/security\.js"\s+output="deviceId"\s*></script>~i',
        '<script defer src="https://www.mercadopago.com/v2/security.js" output="deviceId"></script>'
    );

    // Antes da confirmacao nao existe reserva persistida; nao apresentar um
    // cronometro ficticio ao consumidor.
    $html = svcoh_replace_or_original(
        $html,
        '~Garanta o seu estoque!\s*Os produtos no seu carrinho estão reservados por\s*<strong\s+id="checkout-timer-display"[^>]*>15:00</strong>\s*minutos\.~iu',
        'Estoque, preço, cupom e frete serão revalidados no servidor ao confirmar o pedido.'
    );

    // O backend devolve o total final ja revalidado. Nao recalcular a partir
    // de localStorage, pois isso ignorava descontos e contaminava PIX,
    // WhatsApp, GA4 e Google Ads.
    $html = svcoh_replace_or_original(
        $html,
        '~var\s+shippingQuote\s*=\s*getShippingQuote\(\);\s*var\s+shippingTotal\s*=\s*shippingQuote\s*&&\s*Number\(shippingQuote\.total\s*\|\|\s*0\)\s*>\s*0\s*\?\s*Number\(shippingQuote\.total\s*\|\|\s*0\)\s*:\s*0;\s*var\s+total\s*=\s*items\.reduce\(function\(a,i\)\{\s*return\s+a\+\(parseFloat\(i\.price\)\|\|0\)\*\(i\.quantity\|\|1\);\s*\},\s*0\)\s*\+\s*shippingTotal;\s*var\s+totalFmt\s*=\s*fmtMoney\(total\);~s',
        "var shippingQuote = getShippingQuote();\n            var shippingTotal = Number(order.shipping_total || 0);\n            var total = Number(order.total);\n            if (!Number.isFinite(total) || total < 0) { throw new Error('O servidor retornou um total inválido. Recarregue o checkout.'); }\n            var totalFmt = fmtMoney(total);\n            window.ShopVivalizLastOrder = order;"
    );

    // Mantem o fallback legado coerente caso seja reativado por rollback.
    $html = svcoh_replace_or_original(
        $html,
        '~var\s+shippingQuote\s*=\s*getShippingQuote\(\);\s*var\s+shippingTotal\s*=\s*shippingQuote\s*&&\s*Number\(shippingQuote\.total\s*\|\|\s*0\)\s*>\s*0\s*\?\s*Number\(shippingQuote\.total\s*\|\|\s*0\)\s*:\s*0;\s*var\s+total\s*=\s*getCart\(\)\.reduce\(function\(a,i\)\{\s*return\s+a\+\(parseFloat\(i\.price\)\|\|0\)\*\(i\.quantity\|\|1\);\s*\},\s*0\)\s*\+\s*shippingTotal;~s',
        "var shippingQuote = getShippingQuote();\n            var shippingTotal = Number(d.shipping_total || 0);\n            var total = Number(d.total);\n            if (!Number.isFinite(total) || total < 0) { throw new Error('Total autoritativo indisponível.'); }"
    );

    if (!str_contains($html, '/js/checkout-resilience-v1.js')) {
        $html = str_replace(
            '</body>',
            '<script defer src="/js/checkout-resilience-v1.js"></script>' . "\n</body>",
            $html
        );
    }

    return $html;
}

function svcoh_register(string $requestPath): void
{
    static $registered = false;
    if ($registered || PHP_SAPI === 'cli' || $requestPath !== '/checkout') {
        return;
    }
    $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    if (!in_array($method, ['GET', 'HEAD'], true)) {
        return;
    }
    $registered = true;
    ob_start('svcoh_filter');
}

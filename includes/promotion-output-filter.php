<?php
declare(strict_types=1);

require_once __DIR__ . '/active-coupons.php';

function sv_promotion_replace_once(string $html, string $pattern, string $replacement): string
{
    $updated = @preg_replace($pattern, $replacement, $html, 1);
    return is_string($updated) ? $updated : $html;
}

function sv_promotion_replace_literal_once(string $html, string $pattern, string $replacement): string
{
    $updated = @preg_replace_callback(
        $pattern,
        static fn(array $matches): string => (string)($matches[1] ?? '') . $replacement . (string)($matches[2] ?? ''),
        $html,
        1
    );
    return is_string($updated) ? $updated : $html;
}

/**
 * Garante que o primeiro banner da home nunca prometa um cupom diferente da
 * fonte de cupons ativos. A origem do banner ainda e estatica, mas a copy
 * promocional entregue ao cliente e fail-closed.
 *
 * @param array<string,mixed>|null|false $couponOverride false usa a fonte real;
 * null forca o cenario sem cupom para teste deterministico.
 */
function sv_promotion_filter_home_html(string $html, array|null|false $couponOverride = false): string
{
    if ($html === '') {
        return $html;
    }

    $coupon = $couponOverride === false ? sv_primary_active_coupon() : $couponOverride;
    if (is_array($coupon) && trim((string)($coupon['code'] ?? '')) !== '') {
        $code = htmlspecialchars((string)$coupon['code'], ENT_QUOTES, 'UTF-8');
        $offer = htmlspecialchars(sv_active_coupon_offer_text($coupon), ENT_QUOTES, 'UTF-8');
        $alt = 'Banner Vivaliz com oferta ativa';
        $tag = 'OFERTA ATIVA';
        $subtitle = 'Aproveite ' . $offer . ' com o cupom ' . $code . '. Confira as condições no checkout.';
        $cta = 'Ver oferta';
    } else {
        $alt = 'Banner Vivaliz com produtos para casa e organização';
        $tag = 'DESTAQUES VIVALIZ';
        $subtitle = 'Encontre rodízios, ferragens, ferramentas e utilidades para facilitar sua rotina.';
        $cta = 'Ver produtos';
    }

    $html = sv_promotion_replace_once(
        $html,
        '~alt="Banner Vivaliz com [^"]*(?:desconto|primeira compra)[^"]*"~iu',
        'alt="' . $alt . '"'
    );
    $html = sv_promotion_replace_once(
        $html,
        '~(<span\s+class="banner-tag[^\"]*">)OFERTA EXCLUSIVA(</span>)~iu',
        '$1' . $tag . '$2'
    );
    $html = sv_promotion_replace_literal_once(
        $html,
        '~(<p\s+class="banner-subtitle[^\"]*">)[^<]*(?:cupom|primeira compra)[^<]*(</p>)~iu',
        $subtitle
    );
    $html = sv_promotion_replace_once(
        $html,
        '~(class="btn\s+btn-primary\s+btn-cta-green"[^>]*>)(?:Aproveitar Desconto|Ver oferta|Ver produtos)(</a>)~iu',
        '$1' . $cta . '$2'
    );

    return $html;
}

function sv_promotion_normalize_home_links(string $html): string
{
    if ($html === '') {
        return $html;
    }

    $patterns = [
        '#https://shopvivaliz\.com\.br/catalogo(?=(?:\?|["\']))#' => 'https://shopvivaliz.com.br/catalogo/',
        '#https://shopvivaliz\.com\.br/contato(?=(?:\?|["\']))#' => 'https://shopvivaliz.com.br/contato/',
        '#https://shopvivaliz\.com\.br/blog(?=(?:\?|["\']))#' => 'https://shopvivaliz.com.br/blog/',
        '#(?<=["\'])/catalogo(?=(?:\?|["\']))#' => '/catalogo/',
        '#(?<=["\'])/contato(?=(?:\?|["\']))#' => '/contato/',
        '#(?<=["\'])/blog(?=(?:\?|["\']))#' => '/blog/',
    ];

    foreach ($patterns as $pattern => $replacement) {
        $html = preg_replace($pattern, $replacement, $html) ?? $html;
    }

    return $html;
}

function sv_promotion_output_filter_register(): void
{
    static $registered = false;
    if ($registered || PHP_SAPI === 'cli') {
        return;
    }

    $script = basename((string)($_SERVER['SCRIPT_FILENAME'] ?? $_SERVER['SCRIPT_NAME'] ?? ''));
    if ($script !== 'index.php') {
        return;
    }

    $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    if (!in_array($method, ['GET', 'HEAD'], true)) {
        return;
    }

    $registered = true;
    ob_start(static fn(string $html): string => sv_promotion_normalize_home_links(sv_promotion_filter_home_html($html)));
}

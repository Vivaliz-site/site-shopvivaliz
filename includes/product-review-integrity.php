<?php
declare(strict_types=1);

/**
 * Integrity guard for legacy product markup.
 *
 * The product template still contains a hard-coded rating and two static
 * testimonials with no order/review record behind them. They must not be sent
 * to customers or search engines. This output filter removes only those exact
 * legacy blocks until the large product template is split into components and
 * a real verified-purchase review repository is implemented.
 *
 * The same legacy template also renders "1 unidades restantes". Until the
 * stock component is extracted, normalize only that exact singular case in
 * the final HTML without changing stock values or availability rules.
 *
 * The legacy cross-sell block also says "Economize" even though it displays
 * the arithmetic sum of the two normal prices, and its CTA only navigates to
 * the complementary product. Keep the cross-sell, but make the rendered copy
 * accurately describe the current behavior. Likewise, avoid claiming a
 * manufacturer warranty for every SKU when no per-product warranty data is
 * available in this template.
 */
function sv_product_review_integrity_filter(string $html): string
{
    $patterns = [
        '~\s*<!-- Customer Reviews Widget -->\s*<section\s+class="container\s+sv-reviews-section".*?</section>~si',
        '~\s*<div\s+style="color:\s*#fbbf24;[^\"]*"[^>]*>\s*★★★★★\s*<span[^>]*>\s*\(4\.9/5\s*-\s*Excelente\)\s*</span>\s*</div>~si',
    ];

    $filtered = preg_replace($patterns, '', $html);
    if (!is_string($filtered)) {
        error_log('[ReviewIntegrity] output filter failed; serving original response');
        return $html;
    }

    $stockNormalized = preg_replace(
        '~(<div\s+class="urgency-tag"[^>]*>\s*<i>🔥</i>\s* Apenas\s+)1\s+unidades\s+restantes!~iu',
        '${1}1 unidade restante!',
        $filtered
    );
    if (!is_string($stockNormalized)) {
        error_log('[ReviewIntegrity] stock copy normalization failed; serving review-filtered response');
        return $filtered;
    }

    $truthfulCopy = str_replace(
        [
            'Compre Junto e Economize (Combo Recomendado)',
            '>Adicionar Combo</a>',
            '<span>Garantia de Fábrica</span>',
        ],
        [
            'Complete sua compra (produto complementar)',
            '>Ver produto complementar</a>',
            '<span>Suporte antes e depois da compra</span>',
        ],
        $stockNormalized
    );

    return $truthfulCopy;
}

function sv_enable_product_review_integrity_guard(): void
{
    static $enabled = false;
    if ($enabled) return;
    $enabled = true;
    ob_start('sv_product_review_integrity_filter');
}

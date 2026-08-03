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

    return $filtered;
}

function sv_enable_product_review_integrity_guard(): void
{
    static $enabled = false;
    if ($enabled) return;
    $enabled = true;
    ob_start('sv_product_review_integrity_filter');
}

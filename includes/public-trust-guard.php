<?php
declare(strict_types=1);

/**
 * Removes unverified social proof from public storefront HTML.
 *
 * The guard is deliberately scoped to index.php and produto.php and runs only
 * for browser GET/HEAD requests. It prevents hard-coded ratings/testimonials
 * from being published while the verified-review repository is not wired into
 * those templates.
 */

function svptg_sanitize_html(string $html, string $script): string
{
    if ($html === '') {
        return $html;
    }

    if ($script === 'index.php') {
        $html = (string)preg_replace(
            '~\s*<!-- Testimonials Section -->\s*<section\s+class="home-testimonials\b.*?</section>\s*~si',
            "\n",
            $html,
            1
        );

        $html = (string)preg_replace(
            '~,\s*\{\s*"@type"\s*:\s*"AggregateRating"\s*,.*?"worstRating"\s*:\s*"1"\s*\}\s*(?=,)~si',
            '',
            $html,
            1
        );
    }

    if ($script === 'produto.php') {
        $html = (string)preg_replace(
            '~\s*<div\s+style="color:\s*#fbbf24;[^"]*">\s*★★★★★\s*<span[^>]*>\(4\.9/5\s*-\s*Excelente\)</span>\s*</div>\s*~si',
            "\n",
            $html,
            1
        );

        $html = (string)preg_replace(
            '~\s*<!-- Customer Reviews Widget -->\s*<section\s+class="container\s+sv-reviews-section\b.*?</section>\s*~si',
            "\n",
            $html,
            1
        );

        $html = str_replace(
            'Garantia de Fábrica',
            'Garantia legal e do fabricante, quando aplicável',
            $html
        );
    }

    return $html;
}

function svptg_register(): void
{
    static $registered = false;
    if ($registered || PHP_SAPI === 'cli') {
        return;
    }

    $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    if (!in_array($method, ['GET', 'HEAD'], true)) {
        return;
    }

    $script = basename((string)($_SERVER['SCRIPT_FILENAME'] ?? $_SERVER['SCRIPT_NAME'] ?? ''));
    if (!in_array($script, ['index.php', 'produto.php'], true)) {
        return;
    }

    $registered = true;
    ob_start(static fn(string $html): string => svptg_sanitize_html($html, $script));
}

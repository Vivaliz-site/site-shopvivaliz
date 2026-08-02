<?php
declare(strict_types=1);

/**
 * Removes unverified social proof from public storefront HTML while preserving
 * the mount points used by the API-backed, moderated testimonial experience.
 */

function svptg_replace_or_original(
    string $html,
    string $pattern,
    string $replacement,
    int $limit = 1
): string {
    $updated = @preg_replace($pattern, $replacement, $html, $limit);
    return is_string($updated) ? $updated : $html;
}

function svptg_sanitize_html(string $html, string $script): string
{
    if ($html === '') {
        return $html;
    }

    if ($script === 'index.php') {
        $verifiedMount = <<<'HTML'
<!-- Testimonials Section -->
<section class="home-testimonials home-section-shell home-section-soft" aria-labelledby="sv-testimonials-title">
  <div class="container">
    <div class="section-heading">
      <span class="section-eyebrow">Avaliações</span>
      <h2 id="sv-testimonials-title">Experiências de clientes</h2>
      <p>Avaliações enviadas por clientes e publicadas somente após moderação da equipe.</p>
    </div>
    <div class="testimonials-grid">
      <div class="sv-testimonial-empty">Carregando avaliações reais...</div>
    </div>
  </div>
</section>
HTML;

        $html = svptg_replace_or_original(
            $html,
            '~\s*<!-- Testimonials Section -->\s*<section\s+class="home-testimonials\b.*?</section>\s*~si',
            "\n" . $verifiedMount . "\n"
        );

        $html = svptg_replace_or_original(
            $html,
            '~,\s*\{\s*"@type"\s*:\s*"AggregateRating"\s*,.*?"worstRating"\s*:\s*"1"\s*\}\s*(?=,)~si',
            ''
        );
    }

    if ($script === 'produto.php') {
        $html = svptg_replace_or_original(
            $html,
            '~\s*<div\s+style="color:\s*#fbbf24;[^"]*">\s*★★★★★\s*<span[^>]*>\(4\.9/5\s*-\s*Excelente\)</span>\s*</div>\s*~si',
            "\n"
        );

        $html = svptg_replace_or_original(
            $html,
            '~\s*<!-- Customer Reviews Widget -->\s*<section\s+class="container\s+sv-reviews-section\b.*?</section>\s*~si',
            "\n"
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

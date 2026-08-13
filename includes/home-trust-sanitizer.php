<?php

declare(strict_types=1);

/**
 * Defesa server-side para a home publica.
 *
 * O index.php ainda e um arquivo monolitico e historicamente acumulou blocos
 * demonstrativos. Este filtro roda somente na home, antes do primeiro byte de
 * HTML, para impedir que crawlers, navegadores sem JS e caches recebam prova
 * social ou formularios que nao estejam lastreados por uma fonte real.
 */
function svhts_is_home_path(string $requestPath): bool
{
    return $requestPath === '/' || $requestPath === '/index.php';
}

function svhts_sanitize_home_html(string $html): string
{
    if ($html === '') {
        return $html;
    }

    $sanitized = $html;

    // O AggregateRating legado (4.8 / 347) nao possui fonte auditavel. Remove
    // somente o objeto historico conhecido e preserva o restante do @graph.
    $sanitized = preg_replace(
        '~,\s*\{\s*"@type"\s*:\s*"AggregateRating"\s*,\s*"name"\s*:\s*"Avaliações Vivaliz".*?"ratingValue"\s*:\s*"4\.8".*?"ratingCount"\s*:\s*"347".*?"worstRating"\s*:\s*"1"\s*\}\s*(?=,\s*\{\s*"@type"\s*:\s*"BreadcrumbList")~su',
        '',
        $sanitized
    ) ?? $sanitized;

    // A home antiga embutia tres depoimentos demonstrativos no HTML e apenas
    // depois o JS tentava substitui-los. O servidor passa a entregar um estado
    // neutro; public-experience-v1.js continua carregando /api/testimonials.php
    // e exibe somente avaliacoes realmente publicadas/moderadas.
    $reviewsReplacement = <<<'HTML'
<!-- Testimonials Section -->
<section class="home-testimonials home-section-shell home-section-soft">
    <div class="container">
        <div class="section-heading section-heading-centered sv-reveal home-section-intro">
            <div>
                <h2>O que nossos clientes dizem</h2>
                <p class="muted">Avaliações enviadas por clientes e publicadas somente após moderação da equipe.</p>
            </div>
        </div>
        <div class="testimonials-grid">
            <div class="sv-testimonial-empty"><strong>Avaliações reais, sem conteúdo demonstrativo.</strong><br>Os relatos publicados são carregados da base moderada da ShopVivaliz.</div>
        </div>
        <div class="sv-testimonial-actions">
            <a class="sv-testimonial-primary" href="/avaliacoes.php">Enviar minha avaliação</a>
            <a class="sv-testimonial-secondary" href="/avaliacoes.php#como-funciona">Como funciona a moderação</a>
        </div>
    </div>
</section>

<!-- Premium Newsletter Section -->
HTML;
    $sanitized = preg_replace(
        '~<!--\s*Testimonials Section\s*-->.*?<!--\s*Premium Newsletter Section\s*-->~su',
        $reviewsReplacement,
        $sanitized,
        1
    ) ?? $sanitized;

    // Nao existe backend de newsletter comprovado nesta rotina. Em vez de
    // alert("sucesso") e resetar o formulario sem persistir nada, entrega CTAs
    // reais que levam a ofertas e atendimento.
    $newsletterReplacement = <<<'HTML'
<!-- Premium Newsletter Section -->
<section class="home-newsletter">
    <div class="container">
        <div class="newsletter-card">
            <div class="newsletter-info">
                <h2>Novidades e ofertas da ShopVivaliz</h2>
                <p>A inscrição por e-mail ainda não está ativa. Consulte as ofertas atuais no catálogo ou fale com nosso atendimento.</p>
            </div>
            <div class="newsletter-form" role="group" aria-label="Acessos para novidades e ofertas">
                <a class="btn btn-primary" href="/catalogo">Ver ofertas atuais</a>
                <a class="btn btn-secondary" href="/contato">Falar com atendimento</a>
            </div>
        </div>
    </div>
</section>

<!-- FAQ Section -->
HTML;
    $sanitized = preg_replace(
        '~<!--\s*Premium Newsletter Section\s*-->.*?<!--\s*FAQ Section\s*-->~su',
        $newsletterReplacement,
        $sanitized,
        1
    ) ?? $sanitized;

    // O fallback externo historico nao deve reaparecer no HTML sem JS. O fluxo
    // de categorias substitui por fotos reais; este fallback server-side fica
    // local e neutro em caso de indisponibilidade do catalogo.
    $sanitized = str_replace(
        'https://images.unsplash.com/photo-1497366216548-37526070297c?w=400&h=400&fit=crop',
        '/public/assets/category-images/cat-organizacao.jpg',
        $sanitized
    );
    $sanitized = preg_replace(
        '~\s*<link\s+rel="(?:preconnect|dns-prefetch)"\s+href="https://images\.unsplash\.com"[^>]*>\s*~i',
        "\n",
        $sanitized
    ) ?? $sanitized;

    return $sanitized;
}

function svhts_register(string $requestPath): void
{
    static $registered = false;
    if ($registered || !svhts_is_home_path($requestPath)) {
        return;
    }
    $registered = true;

    ob_start(static function (string $buffer): string {
        return svhts_sanitize_home_html($buffer);
    });
}

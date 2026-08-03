<?php

declare(strict_types=1);

function svpts_honest_reviews_section(): string
{
    return <<<'HTML'
<section class="container sv-reviews-section" aria-labelledby="sv-product-reviews-title" style="margin-top:40px;padding:24px;background:#fff;border:1px solid rgba(11,79,136,.1);border-radius:20px;">
    <h2 id="sv-product-reviews-title" style="font-size:20px;font-weight:800;color:#07345d;margin:0 0 8px;">Avaliações de clientes</h2>
    <p style="margin:0 0 16px;color:#475569;line-height:1.6;">Ainda não há avaliações publicadas para este produto. Avaliações só aparecem depois de enviadas por clientes e moderadas pela equipe.</p>
    <a href="/avaliacoes.php" class="btn btn-secondary" style="display:inline-flex;text-decoration:none;">Enviar minha avaliação</a>
</section>
HTML;
}

function svpts_sanitize_product_html(string $html): string
{
    $patterns = [
        '~<div\s+style="color:\s*#fbbf24;[^\"]*">\s*★★★★★.*?\(4\.9/5\s*-\s*Excelente\).*?</div>~si' => '',
        '~<!--\s*Customer Reviews Widget\s*-->\s*<section\s+class="container sv-reviews-section".*?</section>~si' => svpts_honest_reviews_section(),
        '~<section\s+class="container sv-compre-junto".*?</section>~si' => '',
    ];

    $sanitized = preg_replace(array_keys($patterns), array_values($patterns), $html);
    if (!is_string($sanitized)) {
        error_log('product-trust-sanitizer: regex failure');
        return $html;
    }

    // Defesa em profundidade: nenhum texto de prova social simulada pode sair
    // mesmo que o markup legado seja alterado e deixe de casar com o regex.
    $forbidden = [
        'Carlos M. - Divinópolis/MG',
        'Fernanda S. - São Paulo/SP',
        '(Baseado em compras verificadas)',
        '4.9 / 5.0',
        '4.9/5 - Excelente',
        'Compre Junto e Economize (Combo Recomendado)',
    ];
    foreach ($forbidden as $claim) {
        $sanitized = str_replace($claim, '', $sanitized);
    }

    return $sanitized;
}

function svpts_register(string $requestPath): void
{
    $normalized = '/' . trim($requestPath, '/');
    if (!str_starts_with($normalized, '/produto')) {
        return;
    }

    ob_start(static function (string $buffer): string {
        return svpts_sanitize_product_html($buffer);
    });
}

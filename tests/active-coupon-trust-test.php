<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/active-coupons.php';
require_once __DIR__ . '/../includes/promotion-output-filter.php';

function svact_assert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$navbarSource = (string)file_get_contents(__DIR__ . '/../includes/navbar.php');
$endpointSource = (string)file_get_contents(__DIR__ . '/../api/coupons/active.php');

svact_assert(!str_contains($navbarSource, 'VOLTEI5'), 'navbar nao pode hard-codear VOLTEI5');
svact_assert(str_contains($navbarSource, 'sv_primary_active_coupon'), 'navbar deve consultar a fonte central de cupom ativo');
svact_assert(str_contains($endpointSource, 'sv_active_coupons(6)'), 'endpoint deve reutilizar a fonte central de cupons ativos');

svact_assert(
    sv_active_coupon_offer_text(['type' => 'percent', 'value' => 5]) === '5% OFF',
    'percentual deve ser formatado sem inventar condicao'
);
svact_assert(
    sv_active_coupon_offer_text(['type' => 'fixed', 'value' => 12.5]) === 'R$ 12,50 OFF',
    'desconto fixo deve ser formatado em BRL'
);
svact_assert(
    sv_active_coupon_offer_text(['type' => 'shipping', 'value' => 0]) === 'FRETE GRÁTIS',
    'cupom de frete deve usar rotulo de frete gratis'
);

$legacyBanner = <<<'HTML'
<article class="hero-slide">
<img alt="Banner Vivaliz com 5% de desconto na primeira compra">
<span class="banner-tag color-accent-green">OFERTA EXCLUSIVA</span>
<p class="banner-subtitle color-text-muted">Ganhe 5% de desconto na sua primeira compra com o cupom VOLTEI5.</p>
<a class="btn btn-primary btn-cta-green" href="/catalogo">Aproveitar Desconto</a>
</article>
HTML;

$withoutCoupon = sv_promotion_filter_home_html($legacyBanner, null);
svact_assert(!str_contains($withoutCoupon, 'VOLTEI5'), 'banner sem cupom ativo nao pode expor codigo legado');
svact_assert(!str_contains($withoutCoupon, '5%'), 'banner sem cupom ativo nao pode prometer percentual');
svact_assert(str_contains($withoutCoupon, 'DESTAQUES VIVALIZ'), 'banner sem cupom deve virar copy evergreen');
svact_assert(str_contains($withoutCoupon, '>Ver produtos</a>'), 'CTA sem cupom deve ser evergreen');

$activeCoupon = [
    'code' => 'ATIVO12',
    'type' => 'percent',
    'value' => 12,
];
$withCoupon = sv_promotion_filter_home_html($legacyBanner, $activeCoupon);
svact_assert(!str_contains($withCoupon, 'VOLTEI5'), 'banner com cupom ativo deve remover codigo legado');
svact_assert(str_contains($withCoupon, 'ATIVO12'), 'banner deve usar codigo da fonte ativa');
svact_assert(str_contains($withCoupon, '12% OFF'), 'banner deve usar beneficio da fonte ativa');
svact_assert(str_contains($withCoupon, 'Confira as condições no checkout'), 'banner deve lembrar que o checkout valida a condicao final');

fwrite(STDOUT, "active-coupon-trust-test: ok\n");

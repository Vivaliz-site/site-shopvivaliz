<?php

declare(strict_types=1);

$root = dirname(__DIR__);

function sv_contract_read(string $root, string $path): string
{
    $full = $root . '/' . ltrim($path, '/');
    if (!is_file($full)) {
        fwrite(STDERR, "FALHOU: arquivo ausente {$path}\n");
        exit(1);
    }
    $content = file_get_contents($full);
    if (!is_string($content) || $content === '') {
        fwrite(STDERR, "FALHOU: arquivo vazio {$path}\n");
        exit(1);
    }
    return $content;
}

function sv_contract_assert(bool $condition, string $message): void
{
    if ($condition) return;
    fwrite(STDERR, "FALHOU: {$message}\n");
    exit(1);
}

$carousel = sv_contract_read($root, 'js/auto-image-carousel.js');
$homeCss = sv_contract_read($root, 'css/home-mobile-corrections-v1.css');
$manifest = sv_contract_read($root, 'includes/asset-bundle-manifest.php');
$lizAssets = sv_contract_read($root, 'includes/liz-assistant-assets.php');
$lizJs = sv_contract_read($root, 'public/assets/liz-assistant/liz-assistant-corrections-v1.js');
$lizCss = sv_contract_read($root, 'public/assets/liz-assistant/liz-assistant-corrections-v1.css');
$promoJs = sv_contract_read($root, 'js/mixed-cart-promo-v1.js');
$promoCss = sv_contract_read($root, 'css/mixed-cart-promo-v1.css');

sv_contract_assert(str_contains($carousel, 'var ROTATION_INTERVAL = 3000;'), 'Rotacao precisa permanecer em 3 segundos.');
sv_contract_assert(str_contains($carousel, "element.classList.contains('category-slide-image-wrapper')"), 'Categorias precisam ter tratamento proprio.');
sv_contract_assert(str_contains($carousel, 'images = images.filter(isRealProductImage);'), 'Categoria deve usar somente fotos reais de produtos.');
sv_contract_assert(str_contains($carousel, 'IntersectionObserver'), 'Rotacao deve pausar fora da viewport.');
sv_contract_assert(str_contains($carousel, 'prefers-reduced-motion'), 'Rotacao deve respeitar movimento reduzido.');
sv_contract_assert(str_contains($carousel, "document.addEventListener('visibilitychange'"), 'Rotacao deve pausar com aba oculta.');

sv_contract_assert(str_contains($manifest, 'css/home-mobile-corrections-v1.css'), 'Correcao mobile precisa entrar no bundle da home.');
sv_contract_assert(str_contains($homeCss, 'section-heading::before'), 'Ornamentos que cruzam titulos devem ser neutralizados.');
sv_contract_assert(str_contains($homeCss, 'safe-area-inset-bottom'), 'UI fixa deve respeitar safe area.');
sv_contract_assert(str_contains($homeCss, ':has(#sv-privacy-consent)'), 'Consentimento deve ter prioridade sobre elementos flutuantes.');

sv_contract_assert(str_contains($lizAssets, 'liz-assistant-corrections-v1.css'), 'CSS de correcao da Liz precisa ser carregado.');
sv_contract_assert(str_contains($lizAssets, 'liz-assistant-corrections-v1.js'), 'JS de correcao da Liz precisa ser carregado.');
sv_contract_assert(str_contains($lizJs, "const MASCOT_AVATAR = '/public/assets/liz-assistant/liz-avatar.png';"), 'Botao da Liz deve usar o mascote.');
sv_contract_assert(str_contains($lizJs, "const MASCOT_VIDEO = '/public/assets/liz-assistant/liz-acenando.webm';"), 'Dialogo da Liz deve preservar o mascote animado.');
sv_contract_assert(!str_contains($lizJs, 'OFFICIAL_LOGO'), 'Logo da loja nao pode substituir o mascote da Liz.');
sv_contract_assert(str_contains($lizCss, '#sv-liz-launcher img'), 'Mascote precisa de enquadramento proprio no botao.');
sv_contract_assert(str_contains($lizCss, '#sv-liz-panel .sv-hero video'), 'Mascote animado precisa de enquadramento no dialogo.');
sv_contract_assert(str_contains($lizCss, 'top: clamp(108px, 17vh, 150px) !important;'), 'Liz desktop deve ficar acima da busca no primeiro fold.');
sv_contract_assert(str_contains($lizCss, 'body.sv-home-top .sv-support-dock'), 'WhatsApp desktop deve sair da faixa promocional no primeiro fold.');

sv_contract_assert(str_contains($promoJs, '3% OFF com 2+ produtos diferentes'), 'Oferta compacta deve manter a regra de produtos diferentes.');
sv_contract_assert(str_contains($promoCss, 'grid-template-columns: 34px minmax(0, 1fr) auto;'), 'Banner promocional mobile deve usar layout compacto.');
sv_contract_assert(str_contains($promoCss, '.sv-mixed-promo-home .sv-mixed-promo__copy'), 'Banner promocional precisa de tratamento exclusivo na home.');

fwrite(STDOUT, "COMPROVADO: categorias rotacionam fotos reais, Liz exibe o mascote e a oferta de 3% fica compacta no mobile.\n");

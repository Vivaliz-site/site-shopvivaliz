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
sv_contract_assert(str_contains($lizJs, "const OFFICIAL_LOGO = '/public/assets/liz-assistant/logo-oficial.svg';"), 'Liz deve usar a marca oficial.');
sv_contract_assert(str_contains($lizJs, "hero.innerHTML = '';"), 'Arte antiga do dialogo deve ser removida.');
sv_contract_assert(str_contains($lizCss, '#sv-liz-launcher img'), 'Avatar oficial precisa de enquadramento proprio.');
sv_contract_assert(str_contains($lizCss, '.sv-liz-official-brand'), 'Dialogo deve ter faixa oficial da marca.');

fwrite(STDOUT, "COMPROVADO: categorias rotacionam fotos reais a cada 3s e Liz usa identidade oficial com layout mobile seguro.\n");

<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$failures = [];

function sv_audit_read(string $path): string
{
    $content = @file_get_contents($path);
    if (!is_string($content)) {
        throw new RuntimeException('Nao foi possivel ler: ' . $path);
    }
    return $content;
}

function sv_audit_expect(bool $condition, string $message): void
{
    global $failures;
    if (!$condition) {
        $failures[] = $message;
    }
}

function sv_audit_strlen(string $value): int
{
    return function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
}

$adminIndex = sv_audit_read($root . '/admin/automacao-ia-multicanal/index.php');
$adminDashboard = sv_audit_read($root . '/admin/automacao-ia-multicanal/pages/dashboard.php');
$adminAutomations = sv_audit_read($root . '/admin/automacao-ia-multicanal/pages/automacoes.php');
$adminJs = sv_audit_read($root . '/js/admin-automation.js');
$checkoutCss = sv_audit_read($root . '/css/checkout.css');
$layoutCss = sv_audit_read($root . '/css/layout-polish-v1.css');
$publicJs = sv_audit_read($root . '/js/public-experience-v1.js');
$lizAssets = sv_audit_read($root . '/includes/liz-assistant-assets.php');
$lizIdentity = sv_audit_read($root . '/public/assets/liz-assistant/liz-identity-v2.js');
$genericCarousel = sv_audit_read($root . '/js/auto-image-carousel.js');
$categoryRotation = sv_audit_read($root . '/js/category-real-images-v52.js');
$categoryApi = sv_audit_read($root . '/api/catalog/category-images.php');
$categoryBootstrap = sv_audit_read($root . '/js/shopvivaliz-ab-testing.js');
$orderEntry = sv_audit_read($root . '/api/orders/create.php');
$orderValidated = sv_audit_read($root . '/api/orders/create-validated.php');

// Admin IA: nenhuma rota inexistente ou painel demonstrativo pode parecer real.
sv_audit_expect(str_contains($adminIndex, "'dashboard' => 'pages/dashboard.php'"), 'Dashboard canonico ausente no modulo IA.');
sv_audit_expect(str_contains($adminIndex, "'automacoes' => 'pages/automacoes.php'"), 'Pagina de rotinas canonicas ausente no modulo IA.');
foreach (['pages/produtos.php', 'pages/canais.php', 'pages/historico.php', 'pages/configuracoes.php', 'pages/manual.php'] as $deadRoute) {
    sv_audit_expect(!str_contains($adminIndex, $deadRoute), 'Rota administrativa inexistente voltou: ' . $deadRoute);
}
foreach (['94.3%', '3 rodando agora', 'TikTok - Descrições com Emojis', '96.5%', 'new Chart('] as $fakeState) {
    sv_audit_expect(!str_contains($adminDashboard . $adminAutomations, $fakeState), 'Estado demonstrativo voltou ao admin: ' . $fakeState);
}
sv_audit_expect(str_contains($adminJs, "fetch('/api/health.php'"), 'Health check do admin nao consulta o endpoint real.');

// Checkout: a reserva de estoque acontece no backend apos o envio do pedido.
// Logo, o timer pre-submit nao pode ser apresentado como reserva ja existente.
sv_audit_expect(
    preg_match('/\.checkout-timer-banner\s*\{[^}]*display\s*:\s*none\s*!important/s', $checkoutCss) === 1,
    'Timer de falsa reserva voltou a ficar visivel antes da criacao do pedido.'
);
sv_audit_expect(
    preg_match('/\.payment-opt\s+input\[type=radio\][^{]*\{[^}]*display\s*:\s*none/s', $checkoutCss) !== 1,
    'Radios de pagamento nao podem usar display:none; isso remove foco por teclado.'
);
sv_audit_expect(str_contains($checkoutCss, 'input[type=radio]:focus-visible + .payment-opt-box'), 'Foco visivel dos meios de pagamento ausente.');

// Carrinho: antes da hidratacao nao deve haver CTA efetivamente clicavel nem promessa de frete.
sv_audit_expect(str_contains($layoutCss, 'body:has(#cart-items-list:empty) #btn-checkout'), 'Guarda pre-hidratacao do checkout do carrinho ausente.');
sv_audit_expect(str_contains($layoutCss, 'body:has(#cart-items-list:empty) .free-shipping-container'), 'Guarda pre-hidratacao de frete gratis ausente.');

// Widgets globais: nao cobrir checkout/admin e manter estados que o CSS espera.
sv_audit_expect(str_contains($publicJs, 'if (!isPublicPath(window.location.pathname)) return;'), 'Dock publico pode voltar a ser injetado em rotas sensiveis.');
foreach (['sv-page-catalog', 'sv-catalog-top', 'sv-page-home', 'sv-home-top'] as $stateClass) {
    sv_audit_expect(str_contains($publicJs, $stateClass), 'Estado visual global ausente: ' . $stateClass);
}

// Home: um unico modulo especializado controla as categorias. A origem fornece
// uma foto principal por produto distinto antes de recorrer a outras fotos do
// mesmo item, e o intervalo declarado e de tres segundos.
sv_audit_expect(
    !str_contains($genericCarousel, '.category-slide-image-wrapper[data-images]'),
    'O carrossel generico nao pode disputar os cards de categoria.'
);
foreach ([
    "CATEGORY_ENDPOINT = '/api/catalog/category-images.php'",
    'CATEGORY_ROTATION_INTERVAL = 3000',
    "wrapper.removeAttribute('data-images')",
    'new Image()',
    'state.failed[item.src] = true',
    'IntersectionObserver',
    'visibilitychange',
    'prefers-reduced-motion',
    'INTERACTION_PAUSE = 10000',
    'category.items',
    'catalog-rotation',
] as $token) {
    sv_audit_expect(str_contains($categoryRotation, $token), 'Protecao do carrossel de categorias ausente: ' . $token);
}
foreach ([
    'SV_CATEGORY_IMAGE_LIMIT = 8',
    'sv_category_product_key',
    'sv_category_product_images',
    "'items' => \$items",
    "'rotation_interval_ms' => 3000",
    'Uma foto principal por produto distinto',
] as $token) {
    sv_audit_expect(str_contains($categoryApi, $token), 'Protecao da origem de imagens por categoria ausente: ' . $token);
}
sv_audit_expect(
    str_contains($categoryBootstrap, '/js/category-real-images-v52.js?v='),
    'A home deixou de carregar o modulo canonico de imagens por categoria.'
);

// Liz: manter identidade explicita, retrato focado, fallback de marca e
// alinhamento dos canais flutuantes acima da navegacao inferior do celular.
sv_audit_expect(
    str_contains($lizAssets, '/public/assets/liz-assistant/liz-identity-v2.js?v='),
    'Asset de identidade visual da Liz ausente no loader.'
);
sv_audit_expect(
    !str_contains($lizAssets, 'category-image-rotation-v2.js'),
    'Loader da Liz voltou a carregar um segundo carrossel de categorias.'
);
foreach ([
    '--sv-floating-channel-size:56px',
    'sv-liz-portrait-v2',
    'sv-liz-head-v2',
    'Assistente virtual da ShopVivaliz',
    'BRAND_FALLBACK',
    'body.sv-liz-panel-open .sv-support-dock',
    'sv-has-mobile-bottom-nav',
    'env(safe-area-inset-bottom,0px)',
    '.sv-msg.sv-bot::before',
    'Fale com a Liz',
] as $token) {
    sv_audit_expect(str_contains($lizIdentity, $token), 'Protecao da identidade visual da Liz ausente: ' . $token);
}

// Pedidos: preservar a cadeia autoritativa que recalcula itens e assina frete.
sv_audit_expect(str_contains($orderEntry, "require __DIR__ . '/create-validated.php';"), 'Entrada de pedidos deixou de usar o fluxo validado.');
foreach (['svoa_resolve_items', 'item_price_mismatch', 'shipping_quote_invalid', 'svir_reserve'] as $guard) {
    sv_audit_expect(str_contains($orderValidated, $guard), 'Guarda autoritativa de pedido ausente: ' . $guard);
}

// SEO: produto.php acrescenta " | Vivaliz" depois de svseo_title(..., 70).
// O helper reserva o sufixo para manter o <title> completo dentro de 65 chars,
// sem reduzir o limite de 150 caracteres usado pelo Merchant Feed.
require_once $root . '/includes/product-seo.php';
$seoFixture = [
    'name' => 'Caixa de Ferramentas Profissional Reforcada com Organizador Superior e Travas Metalicas',
    'brand' => 'Fercar',
    'sku' => 'TESTE-SEO-001',
];
$storefrontTitle = svseo_title($seoFixture, 70) . ' | Vivaliz';
$merchantTitle = svseo_title($seoFixture, 150);
sv_audit_expect(sv_audit_strlen($storefrontTitle) <= 65, 'Title completo de produto excede 65 caracteres.');
sv_audit_expect(sv_audit_strlen($merchantTitle) > 56, 'Limite do storefront vazou para o titulo do Merchant Feed.');

if ($failures !== []) {
    foreach ($failures as $failure) {
        fwrite(STDERR, "FAIL: {$failure}\n");
    }
    exit(1);
}

echo "OK: integridade operacional e visual protegida.\n";
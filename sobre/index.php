<?php
declare(strict_types=1);
$company = @include(dirname(__DIR__) . '/config/company-profile.php') ?: [];
$legalName = $company['legal_name'] ?? 'SHOPVIVALIZ LTDA';
$fantasyName = $company['fantasy_name'] ?? 'ShopVivaliz';
$cnpj = $company['cnpj'] ?? '49.903.300/0001-70';
$pageTitle = 'Sobre a ShopVivaliz | Ferragens, ferramentas e utilidades';
$pageDescription = 'Conheça a ShopVivaliz, loja online de ferragens, rodízios, ferramentas e utilidades para casa, organização e manutenção.';
$pageUrl = 'https://shopvivaliz.com.br/sobre/';
$socialImage = 'https://shopvivaliz.com.br/images/logo-vivaliz.png';
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="description" content="<?= htmlspecialchars($pageDescription, ENT_QUOTES, 'UTF-8') ?>">
<title><?= htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8') ?></title>
<link rel="canonical" href="<?= htmlspecialchars($pageUrl, ENT_QUOTES, 'UTF-8') ?>">
<meta property="og:type" content="website">
<meta property="og:site_name" content="ShopVivaliz">
<meta property="og:locale" content="pt_BR">
<meta property="og:title" content="<?= htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8') ?>">
<meta property="og:description" content="<?= htmlspecialchars($pageDescription, ENT_QUOTES, 'UTF-8') ?>">
<meta property="og:url" content="<?= htmlspecialchars($pageUrl, ENT_QUOTES, 'UTF-8') ?>">
<meta property="og:image" content="<?= htmlspecialchars($socialImage, ENT_QUOTES, 'UTF-8') ?>">
<meta name="twitter:card" content="summary_large_image">
<meta name="twitter:title" content="<?= htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8') ?>">
<meta name="twitter:description" content="<?= htmlspecialchars($pageDescription, ENT_QUOTES, 'UTF-8') ?>">
<meta name="twitter:image" content="<?= htmlspecialchars($socialImage, ENT_QUOTES, 'UTF-8') ?>">
<link rel="stylesheet" href="/css/responsive.css">
<link rel="stylesheet" href="/css/footer-pages.css?v=20260728-1">
<script type="application/ld+json">
<?php
// sameAs de marketplaces (Amazon/Mercado Livre/Shopee/TikTok Shop) fica de fora
// ate termos os links reais dos perfis oficiais de cada canal -- ver
// docs/admin-test-user.md e CHANGELOG.md 2026-07-09 sobre por que nao
// inventamos URLs de redes sociais/perfis "provaveis".
$aboutSchema = [
    '@context' => 'https://schema.org',
    '@type' => 'Organization',
    '@id' => 'https://shopvivaliz.com.br/#organization',
    'name' => $fantasyName,
    'legalName' => $legalName,
    'url' => 'https://shopvivaliz.com.br/',
    'logo' => 'https://shopvivaliz.com.br/images/logo-vivaliz.png',
    'address' => [
        '@type' => 'PostalAddress',
        'addressLocality' => 'Divinópolis',
        'addressRegion' => 'MG',
        'addressCountry' => 'BR',
    ],
    'contactPoint' => [
        '@type' => 'ContactPoint',
        'telephone' => '+55-37-99937-4112',
        'contactType' => 'customer service',
    ],
    // sameAs: perfis confirmados. Redes sociais confirmadas ao vivo no rodape
    // do site (footer.php), nao em config/company-profile.php (que fica null
    // por padrao). Amazon confirmada diretamente pelo Fred em 2026-08-15
    // (merchant ID A3L8A2E1VS90Y2). Mercado Livre/Shopee/TikTok Shop ficam de
    // fora -- buscas (nome, CNPJ 49.903.300/0001-70, variacoes "Vivaliz
    // Store"/"Shop Vivaliz") nao retornaram nenhum resultado com confianca
    // real; ShopVivaliz aparenta usar Mercado Shops (loja propria via
    // Mercado Pago) em vez de uma vitrine classica no marketplace Mercado
    // Livre, entao nao ha URL publica equivalente pra adicionar. Ver
    // CHANGELOG.md 2026-07-09 sobre por que nao inventamos URLs "provaveis".
    'sameAs' => [
        'https://www.facebook.com/shopvivaliz/',
        'https://www.instagram.com/shopvivaliz/',
        'https://www.tiktok.com/@shop_vivaliz',
        'https://www.amazon.com.br/s?i=merchant-items&me=A3L8A2E1VS90Y2',
    ],
];
echo json_encode($aboutSchema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
?>
</script>
<?php include dirname(__DIR__) . '/includes/head-analytics.php'; ?>
</head>
<body>
<?php $svNavCurrent = 'sobre'; include __DIR__ . '/../includes/navbar.php'; ?>
<main class="brand-page"><section class="brand-hero"><div class="container"><div class="brand-hero-card"><span class="brand-eyebrow">Sobre a ShopVivaliz</span><h1>Produtos práticos, informação objetiva e atendimento antes e depois da compra.</h1><p>A <?= htmlspecialchars($fantasyName) ?> é uma loja online de Divinópolis, Minas Gerais, voltada a ferragens, rodízios, ferramentas e utilidades para reparar, organizar e cuidar da casa.</p><div class="brand-hero-actions"><a class="brand-btn" href="/catalogo">Explorar o catálogo</a><a class="brand-btn-secondary" href="/contato">Tirar uma dúvida</a></div></div></div></section>
<div class="container"><section class="brand-section"><div class="brand-grid brand-grid-3">
<article class="brand-card"><h2>Empresa identificada</h2><p>O site é operado por <strong><?= htmlspecialchars($legalName) ?></strong>, CNPJ <?= htmlspecialchars($cnpj) ?>, com sede empresarial em Divinópolis/MG. Os dados de contato e as políticas ficam acessíveis no rodapé.</p></article>
<article class="brand-card"><h2>Catálogo para resolver</h2><p>Reunimos itens para manutenção, mobilidade de móveis, fixação, organização e uso doméstico. Cada produto apresenta SKU, preço, disponibilidade, imagens e as especificações que constam no cadastro.</p></article>
<article class="brand-card"><h2>Compra sem surpresa</h2><p>O frete é calculado pelo CEP antes do pagamento. As opções de pagamento válidas aparecem no checkout, e preço e estoque são confirmados novamente ao finalizar o pedido.</p></article>
</div></section><section class="brand-section"><div class="brand-grid"><article class="brand-card"><h2>Antes de comprar</h2><p>Confira medidas, aplicação e compatibilidade na página do produto. Se algo não estiver claro, fale com a equipe e informe o SKU; preferimos esclarecer a dúvida antes do pedido.</p></article><article class="brand-card"><h2>Depois da compra</h2><p>Use o mesmo e-mail do pedido e informe o número da compra para tratar de pagamento, rastreamento, troca ou devolução. Avaliações podem ser positivas ou negativas e só recebem selo de compra verificada após conferência.</p></article></div></section></div></main><?php include __DIR__ . '/../includes/footer.php'; ?></body></html>

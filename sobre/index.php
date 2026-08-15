<?php
declare(strict_types=1);
if (session_status() === PHP_SESSION_NONE) { session_start(); }
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
<main class="brand-page"><section class="brand-hero"><div class="container"><div class="brand-hero-card"><span class="brand-eyebrow">Sobre nós</span><h1>Soluções práticas para casa, organização e manutenção.</h1><p>A <?= htmlspecialchars($fantasyName) ?> reúne ferragens, rodízios, ferramentas e utilidades em um catálogo online com informações claras e atendimento direto.</p><div class="brand-hero-actions"><a class="brand-btn" href="/catalogo">Ver produtos</a><a class="brand-btn-secondary" href="/contato">Falar com a equipe</a></div></div></div></section>
<div class="container"><section class="brand-section"><div class="brand-grid brand-grid-3">
<article class="brand-card"><h2>Quem somos</h2><p><strong><?= htmlspecialchars($legalName) ?></strong>, CNPJ <?= htmlspecialchars($cnpj) ?>, opera a loja online <?= htmlspecialchars($fantasyName) ?>. Desde 2023 também vendemos em marketplaces como Amazon, Mercado Livre, Shopee e TikTok Shop, além do nosso site oficial.</p></article>
<article class="brand-card"><h2>O que oferecemos</h2><p>Produtos para organização, manutenção e uso doméstico, com preço, disponibilidade e condições de entrega apresentados no site e no carrinho.</p></article>
<article class="brand-card"><h2>Nosso compromisso</h2><p>Trabalhar com informação objetiva, atendimento acessível e transparência nas etapas de compra, pagamento, entrega e pós-venda.</p></article>
</div></section></div></main><?php include __DIR__ . '/../includes/footer.php'; ?></body></html>
<?php
declare(strict_types=1);
if (session_status() === PHP_SESSION_NONE) { session_start(); }
$company = @include(dirname(__DIR__) . '/config/company-profile.php') ?: [];
$legalName = $company['legal_name'] ?? 'SHOPVIVALIZ LTDA';
$fantasyName = $company['fantasy_name'] ?? 'ShopVivaliz';
$cnpj = $company['cnpj'] ?? '49.903.300/0001-70';
?>
<!DOCTYPE html><html lang="pt-BR"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><meta name="description" content="Conheça a ShopVivaliz, loja online de ferragens, rodízios e utilidades para casa."><title>Sobre | <?= htmlspecialchars($fantasyName) ?></title><link rel="stylesheet" href="/css/responsive.css"><link rel="stylesheet" href="/css/footer-pages.css?v=20260728-1"></head><body>
<?php $svNavCurrent = 'sobre'; include __DIR__ . '/../includes/navbar.php'; ?>
<main class="brand-page"><section class="brand-hero"><div class="container"><div class="brand-hero-card"><span class="brand-eyebrow">Sobre nós</span><h1>Soluções práticas para casa, organização e manutenção.</h1><p>A <?= htmlspecialchars($fantasyName) ?> reúne ferragens, rodízios, ferramentas e utilidades em um catálogo online com informações claras e atendimento direto.</p><div class="brand-hero-actions"><a class="brand-btn" href="/catalogo">Ver produtos</a><a class="brand-btn-secondary" href="/contato">Falar com a equipe</a></div></div></div></section>
<div class="container"><section class="brand-section"><div class="brand-grid brand-grid-3">
<article class="brand-card"><h2>Quem somos</h2><p><strong><?= htmlspecialchars($legalName) ?></strong>, CNPJ <?= htmlspecialchars($cnpj) ?>, opera a loja online <?= htmlspecialchars($fantasyName) ?>.</p></article>
<article class="brand-card"><h2>O que oferecemos</h2><p>Produtos para organização, manutenção e uso doméstico, com preço, disponibilidade e condições de entrega apresentados no site e no carrinho.</p></article>
<article class="brand-card"><h2>Nosso compromisso</h2><p>Trabalhar com informação objetiva, atendimento acessível e transparência nas etapas de compra, pagamento, entrega e pós-venda.</p></article>
</div></section></div></main><?php include __DIR__ . '/../includes/footer.php'; ?></body></html>

<?php
declare(strict_types=1);
if (session_status() === PHP_SESSION_NONE) { session_start(); }
$pageTitle = 'Dúvidas frequentes sobre compras | ShopVivaliz';
$pageDescription = 'Respostas sobre pedidos, pagamento, boleto, frete, entrega, troca, devolução, segurança e atendimento da ShopVivaliz.';
$pageUrl = 'https://shopvivaliz.com.br/faq/';
$socialImage = 'https://shopvivaliz.com.br/images/logo-vivaliz.png';
$faqSchema = [
    '@context' => 'https://schema.org',
    '@type' => 'FAQPage',
    'mainEntity' => [
        ['@type' => 'Question', 'name' => 'Como acompanho meu pedido?', 'acceptedAnswer' => ['@type' => 'Answer', 'text' => 'Consulte a área Minha Conta e Meus Pedidos. Quando houver rastreamento disponível, o código ou link também poderá ser enviado pelos canais cadastrados.']],
        ['@type' => 'Question', 'name' => 'Como o frete é calculado?', 'acceptedAnswer' => ['@type' => 'Answer', 'text' => 'O valor e o prazo são calculados no carrinho conforme o CEP, os produtos e as transportadoras disponíveis.']],
        ['@type' => 'Question', 'name' => 'Quais formas de pagamento estão disponíveis?', 'acceptedAnswer' => ['@type' => 'Answer', 'text' => 'As opções válidas, incluindo boleto, Pix, cartão e eventual parcelamento, são exibidas no checkout no momento da compra.']],
        ['@type' => 'Question', 'name' => 'Como solicitar troca ou devolução?', 'acceptedAnswer' => ['@type' => 'Answer', 'text' => 'Entre em contato informando o número do pedido e o motivo e consulte a Política de Trocas e Devoluções.']],
        ['@type' => 'Question', 'name' => 'O site é seguro?', 'acceptedAnswer' => ['@type' => 'Answer', 'text' => 'O site utiliza conexão HTTPS e os pagamentos são processados por prestadores especializados.']],
        ['@type' => 'Question', 'name' => 'Posso tirar dúvidas antes de comprar?', 'acceptedAnswer' => ['@type' => 'Answer', 'text' => 'Sim. Use a página de contato para falar com a equipe sobre compatibilidade, estoque, entrega ou pedido.']],
    ],
];
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
<script type="application/ld+json"><?= json_encode($faqSchema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?></script>
<link rel="stylesheet" href="/css/responsive.css">
<link rel="stylesheet" href="/css/footer-pages.css?v=20260728-1">
<?php include dirname(__DIR__) . '/includes/head-analytics.php'; ?>
</head>
<body>
<?php $svNavCurrent = 'faq'; include __DIR__ . '/../includes/navbar.php'; ?>
<main class="brand-page"><section class="brand-hero"><div class="container"><div class="brand-hero-card"><span class="brand-eyebrow">Ajuda</span><h1>Dúvidas frequentes</h1><p>Informações claras sobre compra, pagamento, entrega, troca e atendimento.</p></div></div></section>
<div class="container"><section class="brand-section"><div class="faq-list">
<details><summary>Como acompanho meu pedido?</summary><p>Consulte a área “Minha Conta &gt; Meus Pedidos”. Quando houver rastreamento disponível, o código ou link também poderá ser enviado pelos canais cadastrados.</p></details>
<details><summary>Como o frete é calculado?</summary><p>O valor e o prazo são calculados no carrinho conforme o CEP, os produtos e as transportadoras disponíveis. Não há campanha permanente de frete grátis.</p></details>
<details><summary>Quais formas de pagamento estão disponíveis?</summary><p>As opções válidas, incluindo boleto, Pix, cartão e eventual parcelamento, são exibidas no checkout no momento da compra.</p></details>
<details><summary>Como solicitar troca ou devolução?</summary><p>Entre em contato informando o número do pedido e o motivo. Consulte também a <a href="/politica-devolucoes">Política de Trocas e Devoluções</a>.</p></details>
<details><summary>O site é seguro?</summary><p>O site utiliza conexão HTTPS e os pagamentos são processados por prestadores especializados. Nunca envie senha ou código de confirmação por mensagem.</p></details>
<details><summary>Posso tirar dúvidas antes de comprar?</summary><p>Sim. Use a página de <a href="/contato">contato</a> para falar com a equipe sobre compatibilidade, estoque, entrega ou pedido.</p></details>
</div></section></div></main><?php include __DIR__ . '/../includes/footer.php'; ?></body></html>
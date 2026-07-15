<?php
declare(strict_types=1);

$svSeoTitle = $svSeoTitle ?? 'ShopVivaliz';
$svSeoDescription = $svSeoDescription ?? 'Produtos selecionados com compra segura e atendimento próximo.';
$svSeoImage = $svSeoImage ?? '/images/logo-vivaliz-square.png';
$svSeoUrl = $svSeoUrl ?? (isset($_SERVER['REQUEST_URI']) ? (string)$_SERVER['REQUEST_URI'] : '/');
$svSeoCanonical = 'https://shopvivaliz.com.br' . ($svSeoUrl === '' ? '/' : $svSeoUrl);
?>
<meta name="description" content="<?= htmlspecialchars($svSeoDescription, ENT_QUOTES, 'UTF-8') ?>">
<link rel="canonical" href="<?= htmlspecialchars($svSeoCanonical, ENT_QUOTES, 'UTF-8') ?>">
<meta property="og:type" content="website">
<meta property="og:title" content="<?= htmlspecialchars($svSeoTitle, ENT_QUOTES, 'UTF-8') ?>">
<meta property="og:description" content="<?= htmlspecialchars($svSeoDescription, ENT_QUOTES, 'UTF-8') ?>">
<meta property="og:url" content="<?= htmlspecialchars($svSeoCanonical, ENT_QUOTES, 'UTF-8') ?>">
<meta property="og:image" content="<?= htmlspecialchars($svSeoImage, ENT_QUOTES, 'UTF-8') ?>">
<meta name="twitter:card" content="summary_large_image">
<meta name="twitter:title" content="<?= htmlspecialchars($svSeoTitle, ENT_QUOTES, 'UTF-8') ?>">
<meta name="twitter:description" content="<?= htmlspecialchars($svSeoDescription, ENT_QUOTES, 'UTF-8') ?>">
<meta name="twitter:image" content="<?= htmlspecialchars($svSeoImage, ENT_QUOTES, 'UTF-8') ?>">

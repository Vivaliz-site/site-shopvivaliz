<?php
declare(strict_types=1);

$svSeoTitle = $svSeoTitle ?? 'ShopVivaliz';
$svSeoDescription = $svSeoDescription ?? 'Produtos selecionados com compra segura e atendimento próximo.';
$svSeoImage = $svSeoImage ?? '/images/logo-vivaliz-square-v2.png';
$svSeoUrl = $svSeoUrl ?? (isset($_SERVER['REQUEST_URI']) ? (string)$_SERVER['REQUEST_URI'] : '/');

// Canonicals da vitrine representam a rota estável, nunca filtros/UTMs/query
// strings. Facetas continuam navegáveis, porém convergem para a página base e
// não criam famílias de URLs duplicadas no índice.
$svSeoPath = parse_url($svSeoUrl, PHP_URL_PATH);
$svSeoPath = is_string($svSeoPath) && $svSeoPath !== '' ? $svSeoPath : '/';
$svSeoPath = '/' . ltrim($svSeoPath, '/');
$svSeoCanonical = 'https://shopvivaliz.com.br' . $svSeoPath;
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

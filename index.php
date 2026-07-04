<?php
// Configuração Dinâmica de Ambiente
$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'] ?? 'dev.shopvivaliz.com.br';
define('BASE_URL', $scheme . '://' . $host);
define('APP_NAME', 'ShopVivaliz');
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo APP_NAME; ?></title>
    
    <!-- SEO Dinâmico -->
    <link rel="canonical" href="<?php echo BASE_URL; ?>/">
    <meta property="og:url" content="<?php echo BASE_URL; ?>/">
    <meta property="og:title" content="<?php echo APP_NAME; ?>">
    
    <link rel="stylesheet" href="/css/responsive.css">
</head>
<body>
    <header>
        <nav>
            <a href="<?php echo BASE_URL; ?>">Início</a>
            <a href="<?php echo BASE_URL; ?>/catalogo">Catálogo</a>
            <a href="<?php echo BASE_URL; ?>/admin">Admin</a>
        </nav>
    </header>

    <main>
        <!-- Conteúdo Principal aqui -->
    </main>

    <!-- Scripts Únicos (Sem duplicação) -->
    <script src="/js/catalog.js"></script>
    <script src="/autodev/client.js"></script>
</body>
</html>
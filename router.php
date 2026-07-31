<?php
$uri = urldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));

// Serve static files directly if they exist
if ($uri !== '/' && file_exists(__DIR__ . $uri)) {
    return false;
}

// Clean URL rewrites
if ($uri === '/carrinho' || $uri === '/carrinho/') {
    $_SERVER['SCRIPT_NAME'] = '/carrinho.php';
    require __DIR__ . '/carrinho.php';
    exit;
}

if ($uri === '/checkout' || $uri === '/checkout/') {
    $_SERVER['SCRIPT_NAME'] = '/checkout.php';
    require __DIR__ . '/checkout.php';
    exit;
}

if ($uri === '/sobre' || $uri === '/sobre/') {
    $_SERVER['SCRIPT_NAME'] = '/sobre.php';
    if (file_exists(__DIR__ . '/sobre.php')) {
        require __DIR__ . '/sobre.php';
    } else {
        require __DIR__ . '/index.php';
    }
    exit;
}

if ($uri === '/contato' || $uri === '/contato/') {
    $_SERVER['SCRIPT_NAME'] = '/contato.php';
    if (file_exists(__DIR__ . '/contato.php')) {
        require __DIR__ . '/contato.php';
    } else {
        require __DIR__ . '/index.php';
    }
    exit;
}

if ($uri === '/catalogo' || $uri === '/catalogo/') {
    $_SERVER['SCRIPT_NAME'] = '/catalogo.php';
    require __DIR__ . '/catalogo.php';
    exit;
}

if (preg_match('#^/produto/([^/]+)$#', $uri, $matches)) {
    $_GET['slug'] = $matches[1];
    $_SERVER['SCRIPT_NAME'] = '/produto.php';
    require __DIR__ . '/produto.php';
    exit;
}

// Fallback to index.php
$_SERVER['SCRIPT_NAME'] = '/index.php';
require __DIR__ . '/index.php';

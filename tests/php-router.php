<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$path = parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH) ?: '/';

// O servidor embutido nao aplica .htaccess: nunca delegar dotfiles ao PHP.
if (preg_match('~(?:^|/)\.[^/]*~', $path) === 1) {
    $_SERVER['SCRIPT_FILENAME'] = $root . '/404.php';
    require $_SERVER['SCRIPT_FILENAME'];
    return true;
}

$static = realpath($root . $path);
if ($static !== false && str_starts_with($static, $root) && is_file($static)) {
    return false;
}

if ($path === '/api/testimonials.php') {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => true, 'items' => []]);
    return true;
}
if ($path === '/api/liz-intelligent.php') {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => true, 'status' => 'test']);
    return true;
}

$redirects = [
    '/remote-index.html' => '/',
    '/temp_homepage.html' => '/',
    '/home' => '/',
    '/home.php' => '/',
    '/index-loja.php' => '/',
    '/checkout-legacy/' => '/checkout',
    '/carrinho-legacy/' => '/carrinho',
    '/p/termos-e-condicoes' => '/termos',
];
if (isset($redirects[$path])) {
    header('Location: ' . $redirects[$path], true, 301);
    return true;
}

$routes = [
    '/' => 'index.php',
    '/catalogo' => 'catalogo.php',
    '/carrinho' => 'carrinho.php',
    '/checkout' => 'checkout.php',
    '/termos' => 'termos.php',
    '/403.php' => '403.php',
    '/500.php' => '500.php',
    '/502.php' => '502.php',
    '/503.php' => '503.php',
];

if (isset($routes[$path])) {
    $_SERVER['SCRIPT_FILENAME'] = $root . '/' . $routes[$path];
    require $_SERVER['SCRIPT_FILENAME'];
    return true;
}

if (preg_match('~^/produto/([^/]+)$~', $path, $match) === 1) {
    $_GET['slug'] = rawurldecode($match[1]);
    $_SERVER['SCRIPT_FILENAME'] = $root . '/produto.php';
    require $_SERVER['SCRIPT_FILENAME'];
    return true;
}

$_SERVER['SCRIPT_FILENAME'] = $root . '/404.php';
require $_SERVER['SCRIPT_FILENAME'];
return true;

<?php
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    @session_start();
}

$currentPath = trim((string)parse_url((string)($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH), '/');
$currentPage = basename($_SERVER['PHP_SELF'] ?? '');

if ($currentPath === '' || $currentPage === 'index.php') {
    $svNavCurrent = '';
} elseif (str_starts_with($currentPath, 'catalogo') || str_starts_with($currentPath, 'produto')) {
    $svNavCurrent = 'catalogo';
} elseif (str_starts_with($currentPath, 'carrinho') || str_starts_with($currentPath, 'checkout')) {
    $svNavCurrent = 'carrinho';
} elseif (str_starts_with($currentPath, 'sobre')) {
    $svNavCurrent = 'sobre';
} elseif (str_starts_with($currentPath, 'contato')) {
    $svNavCurrent = 'contato';
} else {
    $svNavCurrent = $currentPath;
}

include __DIR__ . '/navbar.php';

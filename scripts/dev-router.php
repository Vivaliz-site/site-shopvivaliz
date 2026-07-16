<?php
declare(strict_types=1);

// Router for PHP's built-in server, mirroring the public clean-URL rewrites.
$root = dirname(__DIR__);
$path = rawurldecode((string)(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/'));
$relative = trim(str_replace('\\', '/', $path), '/');
if (str_contains($relative, '..')) { http_response_code(400); exit('Bad request'); }

$static = $root . ($path === '/' ? '/index.php' : '/' . $relative);
if ($path !== '/' && is_file($static)) return false;

$candidates = $relative === ''
    ? [$root . '/index.php']
    : [$root . '/' . $relative . '.php', $root . '/' . $relative . '/index.php'];
foreach ($candidates as $candidate) {
    if (is_file($candidate)) { require $candidate; return true; }
}

http_response_code(404);
require $root . '/404.php';

<?php
declare(strict_types=1);

$root = (string)(getenv('SHOPVIVALIZ_TEST_ROOT') ?: dirname(__DIR__));

$rootHtaccess = (string)@file_get_contents($root . '/.htaccess');
$adminHtaccess = (string)@file_get_contents($root . '/admin/.htaccess');
$adminIndex = (string)@file_get_contents($root . '/admin/index.php');
$wrapper = (string)@file_get_contents($root . '/admin/squad-chat.php');

$errors = [];

$redirect = 'RewriteRule ^admin/squad-chat\.html$ /admin/squad-chat.php [R=302,L,NE,QSD,NC]';
if (!str_contains($rootHtaccess, $redirect)) {
    $errors[] = 'legacy squad-chat.html redirect missing';
}
if (!str_contains($rootHtaccess, 'RewriteRule ^admin/squad-chat\.html$ - [F,L,NC]')) {
    $errors[] = 'non-GET legacy squad-chat.html fail-closed rule missing';
}
$adminRedirect = 'RewriteRule ^squad-chat\\.html$ /admin/squad-chat.php [R=302,L,NE,QSD]';
if (!str_contains($adminHtaccess, $adminRedirect)) {
    $errors[] = 'admin-scope legacy redirect missing';
}
if (!str_contains($adminHtaccess, '<IfModule !mod_rewrite.c>') || !str_contains($adminHtaccess, 'Require all denied')) {
    $errors[] = 'admin static HTML fallback deny policy missing';
}
if (!str_contains($wrapper, "require_once dirname(__DIR__) . '/includes/admin-guard.php';")) {
    $errors[] = 'authenticated wrapper is missing admin guard';
}
if (!str_contains($wrapper, "file_get_contents(__DIR__ . '/squad-chat.html')")) {
    $errors[] = 'authenticated wrapper does not render canonical template';
}
if (substr_count($adminIndex, '/admin/squad-chat.php') < 3) {
    $errors[] = 'Squad Chat canonical admin entry points missing';
}
if (substr_count($adminIndex, '/admin/buscador.php') < 2) {
    $errors[] = 'Buscador admin entry points missing';
}
if (!str_contains($adminIndex, '🤖 Buscador') && !str_contains($adminIndex, '>Buscador<')) {
    $errors[] = 'Buscador label missing';
}
if (!str_contains($adminIndex, '>💬 Squad Chat<') && !str_contains($adminIndex, '💬 Squad Chat')) {
    $errors[] = 'Squad Chat label missing';
}

$redirectPos = strpos($rootHtaccess, $redirect);
$realFileStopPos = strpos($rootHtaccess, '# Nao reescrever arquivos e diretorios reais');
if ($redirectPos === false || $realFileStopPos === false || $redirectPos > $realFileStopPos) {
    $errors[] = 'legacy redirect must run before real-file short circuit';
}

if ($errors !== []) {
    fwrite(STDERR, implode(PHP_EOL, $errors) . PHP_EOL);
    exit(1);
}

echo "SQUAD_CHAT_ADMIN_ROUTE_TEST=PASS\n";

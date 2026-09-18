<?php
$router = (string)file_get_contents(__DIR__ . '/php-router.php');
$audit = (string)file_get_contents(__DIR__ . '/storefront-screenshot-audit.mjs');
$errors = [];
foreach ([
    "if (\$path === '/blog' || \$path === '/blog/')",
    "\$root . '/blog/index.php'",
    "preg_match('~^/blog/([a-z0-9][a-z0-9-]*)/?$~'",
    "\$root . '/blog/artigo.php'",
] as $needle) {
    if (!str_contains($router, $needle)) $errors[] = "router_missing:$needle";
}
foreach ([
    "{ name: 'blog', url: '/blog/', expected: [200] }",
    "{ name: 'blog-article', url: '/blog/como-escolher-ferramentas-para-casa', expected: [200] }",
    "const intentionallyOffscreen = style.position === 'absolute'",
] as $needle) {
    if (!str_contains($audit, $needle)) $errors[] = "audit_missing:$needle";
}
if ($errors) { fwrite(STDERR, implode("\n", $errors) . "\n"); exit(1); }
echo "php-router-blog-contract: ok\n";

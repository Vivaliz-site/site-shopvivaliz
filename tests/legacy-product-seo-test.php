<?php
declare(strict_types=1);

$htaccess = file_get_contents(dirname(__DIR__) . '/.htaccess');
if (!is_string($htaccess) || $htaccess === '') {
    fwrite(STDERR, "Unable to read .htaccess\n");
    exit(1);
}

$specific = 'RewriteRule ^produtos/([^/]+)/?$ /produto/$1 [R=301,L,NE,NC]';
$generic = 'RewriteRule ^(?:ferramentas|arte-papelaria-e-armarinho|produtos)(?:/.*)?$ /catalogo/ [R=301,L,NE,QSD,NC]';

$specificPos = strpos($htaccess, $specific);
$genericPos = strpos($htaccess, $generic);

if ($specificPos === false) {
    fwrite(STDERR, "Legacy product redirect to canonical product route is missing\n");
    exit(1);
}
if ($genericPos === false) {
    fwrite(STDERR, "Generic legacy storefront fallback is missing\n");
    exit(1);
}
if ($specificPos >= $genericPos) {
    fwrite(STDERR, "Specific /produtos/<slug> redirect must run before the generic catalog fallback\n");
    exit(1);
}
if (str_contains($specific, 'QSD')) {
    fwrite(STDERR, "Legacy product redirects must preserve attribution query strings\n");
    exit(1);
}

fwrite(STDOUT, "legacy-product-seo-test: ok\n");

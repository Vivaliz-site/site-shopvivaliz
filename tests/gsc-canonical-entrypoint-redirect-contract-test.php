<?php
declare(strict_types=1);
$root = dirname(__DIR__);
$htaccess = (string)file_get_contents($root . '/.htaccess');
$legacyRoutes = [
    'RewriteRule ^busca/?$ /catalogo/ [R=301,L,NE]',
    'RewriteRule ^p/quem-somos/?$ /sobre/ [R=301,L,NE,QSD]',
    'RewriteRule ^p/ajuda/?$ /faq/ [R=301,L,NE,QSD]',
    'RewriteRule ^p/trocas/?$ /politica-devolucoes/ [R=301,L,NE,QSD]',
    'RewriteRule ^p/entrega/?$ /politica-entrega/ [R=301,L,NE,QSD]',
    'RewriteRule ^p/termos/?$ /termos/ [R=301,L,NE,QSD]',
    'RewriteRule ^p/termos-e-condicoes/?$ /termos/ [R=301,L,NE,QSD]',
    'RewriteRule ^p/politica-privacidade/?$ /politica-privacidade/ [R=301,L,NE,QSD]',
];
foreach ($legacyRoutes as $needle) {
    if (!str_contains($htaccess, $needle)) {
        fwrite(STDERR, "Legacy public route must converge by 301: {$needle}\n"); exit(1);
    }
}
$slashRoutes = [
    'RewriteRule ^termos$ /termos/ [R=301,L,NE,QSD]',
    'RewriteRule ^politica-devolucoes$ /politica-devolucoes/ [R=301,L,NE,QSD]',
    'RewriteRule ^politica-entrega$ /politica-entrega/ [R=301,L,NE,QSD]',
];
foreach ($slashRoutes as $needle) {
    if (!str_contains($htaccess, $needle)) { fwrite(STDERR, "Slashless virtual canonical must redirect: {$needle}\n"); exit(1); }
}
$implementationRoutes = [
    'RewriteRule ^index\\.php$ / [R=301,L,NE,QSD]',
    'RewriteRule ^catalogo\\.php$ /catalogo/ [R=301,L,NE,QSD]',
    'RewriteRule ^catalogo/index\\.php$ /catalogo/ [R=301,L,NE,QSD]',
    'RewriteRule ^sobre/index\\.php$ /sobre/ [R=301,L,NE,QSD]',
    'RewriteRule ^contato/index\\.php$ /contato/ [R=301,L,NE,QSD]',
    'RewriteRule ^faq/index\\.php$ /faq/ [R=301,L,NE,QSD]',
    'RewriteRule ^blog/index\\.php$ /blog/ [R=301,L,NE,QSD]',
    'RewriteRule ^politica-privacidade/index\\.php$ /politica-privacidade/ [R=301,L,NE,QSD]',
    'RewriteRule ^termos\\.php$ /termos/ [R=301,L,NE,QSD]',
    'RewriteRule ^politica-devolucoes\\.php$ /politica-devolucoes/ [R=301,L,NE,QSD]',
    'RewriteRule ^politica-entrega\\.php$ /politica-entrega/ [R=301,L,NE,QSD]',
];
foreach ($implementationRoutes as $needle) {
    $pos = strpos($htaccess, $needle);
    if ($pos === false) { fwrite(STDERR, "Direct implementation URL must redirect: {$needle}\n"); exit(1); }
    $before = substr($htaccess, max(0, $pos - 240), 240);
    if (!str_contains($before, 'RewriteCond %{THE_REQUEST}')) { fwrite(STDERR, "Direct implementation redirect must be guarded by THE_REQUEST: {$needle}\n"); exit(1); }
}
fwrite(STDOUT, "gsc-canonical-entrypoint-redirect-contract-test: ok\n");

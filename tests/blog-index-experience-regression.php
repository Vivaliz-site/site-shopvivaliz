<?php
declare(strict_types=1);

$path = __DIR__ . '/../blog/index.php';
$source = (string)file_get_contents($path);

$required = [
    'knowledge-featured',
    'knowledge-topics',
    'knowledge-recent',
    'Ler guia',
    '$featuredArticle',
    '$topicShortcuts',
];
foreach ($required as $needle) {
    if (!str_contains($source, $needle)) {
        fwrite(STDERR, "Experiencia do blog ausente: {$needle}\n");
        exit(1);
    }
}

if (str_contains($source, '<h2>Acompanhe as novidades</h2>')) {
    fwrite(STDERR, "RSS ainda ocupa painel editorial primario.\n");
    exit(1);
}

if (!str_contains($source, '$isFiltered')) {
    fwrite(STDERR, "Modo filtrado do blog precisa ser preservado.\n");
    exit(1);
}

fwrite(STDOUT, "OK blog index experience regression\n");

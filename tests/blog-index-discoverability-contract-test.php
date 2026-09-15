<?php
declare(strict_types=1);
$path = dirname(__DIR__) . '/blog/index.php';
$text = (string)file_get_contents($path);
$needles = [
    '$remainingArticles = [];',
    '$renderedSlugs',
    'knowledge-all-content',
    'Todos os conte&uacute;dos',
    'foreach ($remainingArticles as $article)',
];
foreach ($needles as $needle) {
    if (!str_contains($text, $needle)) {
        fwrite(STDERR, "FAIL: blog index missing discoverability contract: {$needle}\n");
        exit(1);
    }
}
fwrite(STDOUT, "PASS: blog index discoverability contract.\n");

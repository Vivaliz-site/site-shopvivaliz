<?php
declare(strict_types=1);
$root = dirname(__DIR__);
$files = [
    'avaliacoes.php',
    'termos.php',
    'politica-privacidade/index.php',
    'politica-devolucoes.php',
    'politica-entrega.php',
];
$missing = [];
foreach ($files as $rel) {
    $text = file_get_contents($root . '/' . $rel) ?: '';
    foreach (['og:title', 'og:description', 'og:image'] as $property) {
        if (!str_contains($text, 'property="' . $property . '"')) {
            $missing[] = $rel . ':' . $property;
        }
    }
}
if ($missing !== []) {
    fwrite(STDERR, 'missing Open Graph metadata: ' . implode(', ', $missing) . PHP_EOL);
    exit(1);
}
echo "legal-open-graph-regression-test: ok\n";

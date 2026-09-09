<?php
declare(strict_types=1);

$_SERVER['REQUEST_URI'] = '/sobre/';
ob_start();
include __DIR__ . '/../sobre/index.php';
$html = (string)ob_get_clean();

$checks = [
    'og:title' => 'property="og:title"',
    'og:description' => 'property="og:description"',
    'og:image' => 'property="og:image"',
    'canonical' => 'rel="canonical"',
    'description' => 'name="description"',
];

$errors = [];
foreach ($checks as $name => $needle) {
    if (substr_count($html, $needle) !== 1) {
        $errors[] = $name . ' count=' . substr_count($html, $needle);
    }
}
if ($errors) {
    fwrite(STDERR, implode(PHP_EOL, $errors) . PHP_EOL);
    exit(1);
}
if (strpos($html, '/images/logo-vivaliz-square.png') !== false) {
    fwrite(STDERR, "missing fallback logo reference detected\n");
    exit(1);
}
if (strpos($html, '/images/logo-vivaliz-square-v2.png') === false) {
    fwrite(STDERR, "expected fallback logo reference missing\n");
    exit(1);
}
echo "SOBRE_OPEN_GRAPH_OK\n";

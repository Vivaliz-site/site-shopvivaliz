<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$css = (string) file_get_contents($root . '/css/privacy-consent-v1.css');
$js = (string) file_get_contents($root . '/js/privacy-consent-v1.js');

$checks = [
    'mobile layout reserves dynamic consent space' => str_contains($css, '--sv-privacy-consent-space'),
    'mobile reserve overrides global zero padding' => preg_match('/body:has\(#sv-privacy-consent\)[^{]*\{[^}]*padding-bottom:[^;]+!important/s', $css) === 1,
    'consent script measures rendered banner' => str_contains($js, 'getBoundingClientRect().height'),
    'consent script publishes dynamic spacing' => str_contains($js, '--sv-privacy-consent-space'),
    'consent removal clears dynamic spacing' => str_contains($js, "removeProperty('--sv-privacy-consent-space')"),
];

foreach ($checks as $label => $ok) {
    if (!$ok) {
        fwrite(STDERR, "FAIL: {$label}\n");
        exit(1);
    }
}

echo "privacy-consent-mobile-overlap: ok\n";

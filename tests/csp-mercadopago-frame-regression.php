<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$htaccess = file_get_contents($root . '/.htaccess');
if (!is_string($htaccess) || $htaccess === '') {
    fwrite(STDERR, ".htaccess missing or empty\n");
    exit(1);
}

preg_match_all('/Header always set Content-Security-Policy(?:-Report-Only)? "([^"]+)"/', $htaccess, $matches);
if (count($matches[1] ?? []) !== 2) {
    fwrite(STDERR, "expected enforced and report-only CSP headers\n");
    exit(1);
}

foreach ($matches[1] as $policy) {
    if (!preg_match('/(?:^|;\s*)frame-src\s+([^;]+)/', $policy, $frame)) {
        fwrite(STDERR, "frame-src directive missing\n");
        exit(1);
    }
    if (!str_contains($frame[1], 'https://www.mercadolibre.com')) {
        fwrite(STDERR, "Mercado Pago runtime frame origin missing from frame-src\n");
        exit(1);
    }
}

echo "csp_mercadopago_frame_regression=PASS\n";

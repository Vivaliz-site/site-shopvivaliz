<?php
declare(strict_types=1);

$root = dirname(__DIR__, 2);
$errors = [];

$scriptPath = $root . DIRECTORY_SEPARATOR . 'js' . DIRECTORY_SEPARATOR . 'auto-image-carousel.js';
if (!is_file($scriptPath)) {
    $errors[] = 'missing script: js/auto-image-carousel.js';
} elseif (filesize($scriptPath) === 0) {
    $errors[] = 'empty script: js/auto-image-carousel.js';
} else {
    $script = (string) file_get_contents($scriptPath);
    foreach ([
        'ROTATION_INTERVAL = 3000',
        'INTERACTION_PAUSE = 10000',
        'initProductGallery',
        'initProductCardCarousels',
        'IntersectionObserver',
        'pauseFor(INTERACTION_PAUSE)',
    ] as $needle) {
        if (!str_contains($script, $needle)) {
            $errors[] = "carousel script missing token: {$needle}";
        }
    }
}

$pages = [
    'index.php' => '/js/auto-image-carousel.js?v=',
    'home.php' => '/js/auto-image-carousel.js?v=',
    'catalogo.php' => '/js/auto-image-carousel.js?v=',
    'catalogo-v2.php' => '/js/auto-image-carousel.js?v=',
    'produto.php' => '/js/auto-image-carousel.js?v=',
];

foreach ($pages as $file => $needle) {
    $path = $root . DIRECTORY_SEPARATOR . $file;
    if (!is_file($path)) {
        $errors[] = "missing page: {$file}";
        continue;
    }

    $content = (string) file_get_contents($path);
    if (!str_contains($content, $needle)) {
        $errors[] = "{$file} missing carousel include";
    }
}

if ($errors !== []) {
    fwrite(STDERR, implode(PHP_EOL, $errors) . PHP_EOL);
    exit(1);
}

echo "Auto image carousel validation passed.\n";

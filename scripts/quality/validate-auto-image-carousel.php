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
        'initProductGallery',
        'initProductCardCarousels',
        'IntersectionObserver',
        'pauseFor(10000)',
    ] as $needle) {
        if (!str_contains($script, $needle)) {
            $errors[] = "carousel script missing token: {$needle}";
        }
    }
}

$categoryScriptPath = $root . '/public/assets/storefront/category-image-rotation-v2.js';
if (!is_file($categoryScriptPath)) {
    $errors[] = 'missing script: public/assets/storefront/category-image-rotation-v2.js';
} elseif (filesize($categoryScriptPath) === 0) {
    $errors[] = 'empty script: public/assets/storefront/category-image-rotation-v2.js';
} else {
    $categoryScript = (string) file_get_contents($categoryScriptPath);
    foreach ([
        'var INTERVAL_MS = 3000',
        'data-sv-category-images',
        'wrapper.removeAttribute(LEGACY_ATTR)',
        'new Image()',
        'failed[entry.src] = true',
        'IntersectionObserver',
        'visibilitychange',
        'prefers-reduced-motion',
        'Pausar fotos',
        'Reproduzir fotos',
        'INTERACTION_PAUSE_MS',
        'shopvivaliz:category-image-change',
    ] as $needle) {
        if (!str_contains($categoryScript, $needle)) {
            $errors[] = "category rotation script missing token: {$needle}";
        }
    }
}

$assetLoaderPath = $root . '/includes/liz-assistant-assets.php';
if (!is_file($assetLoaderPath)) {
    $errors[] = 'missing asset loader: includes/liz-assistant-assets.php';
} else {
    $assetLoader = (string) file_get_contents($assetLoaderPath);
    if (!str_contains($assetLoader, '/public/assets/storefront/category-image-rotation-v2.js?v=')) {
        $errors[] = 'asset loader missing category rotation v2';
    }
    $categoryPos = strpos($assetLoader, 'category-image-rotation-v2.js');
    $legacyPos = strpos($assetLoader, 'liz-assistant.js');
    if ($categoryPos === false || $legacyPos === false || $categoryPos > $legacyPos) {
        $errors[] = 'category rotation must load before deferred Liz/widget assets';
    }
    if (preg_match('/category-image-rotation-v2\.js[^\n]+defer/', $assetLoader) === 1) {
        $errors[] = 'category rotation cannot be deferred because it must claim cards before the legacy carousel';
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
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
        "document.querySelectorAll('.product-image[data-images]')",
    ] as $needle) {
        if (!str_contains($script, $needle)) {
            $errors[] = "carousel script missing token: {$needle}";
        }
    }
    if (str_contains($script, ".category-slide-image-wrapper[data-images]")) {
        $errors[] = 'generic carousel must not control category cards';
    }
}

$categoryScriptPath = $root . '/js/category-real-images-v52.js';
if (!is_file($categoryScriptPath)) {
    $errors[] = 'missing script: js/category-real-images-v52.js';
} elseif (filesize($categoryScriptPath) === 0) {
    $errors[] = 'empty script: js/category-real-images-v52.js';
} else {
    $categoryScript = (string) file_get_contents($categoryScriptPath);
    foreach ([
        "CATEGORY_ENDPOINT = '/api/catalog/category-images.php'",
        'CATEGORY_ROTATION_INTERVAL = 3000',
        "wrapper.removeAttribute('data-images')",
        'new Image()',
        'state.failed[item.src] = true',
        'IntersectionObserver',
        'visibilitychange',
        'prefers-reduced-motion',
        'INTERACTION_PAUSE = 10000',
        'category.items',
        'catalog-rotation',
    ] as $needle) {
        if (!str_contains($categoryScript, $needle)) {
            $errors[] = "category carousel missing token: {$needle}";
        }
    }
}

$categoryApiPath = $root . '/api/catalog/category-images.php';
if (!is_file($categoryApiPath)) {
    $errors[] = 'missing endpoint: api/catalog/category-images.php';
} else {
    $categoryApi = (string) file_get_contents($categoryApiPath);
    foreach ([
        'SV_CATEGORY_IMAGE_LIMIT = 8',
        'sv_category_product_key',
        'sv_category_product_images',
        "'items' => \$items",
        'array_slice($images, 1)',
        "'rotation_interval_ms' => 3000",
        'Uma foto principal por produto distinto',
    ] as $needle) {
        if (!str_contains($categoryApi, $needle)) {
            $errors[] = "category endpoint missing token: {$needle}";
        }
    }
}

$bootstrapPath = $root . '/js/shopvivaliz-ab-testing.js';
if (!is_file($bootstrapPath)) {
    $errors[] = 'missing category bootstrap: js/shopvivaliz-ab-testing.js';
} else {
    $bootstrap = (string) file_get_contents($bootstrapPath);
    if (!str_contains($bootstrap, '/js/category-real-images-v52.js?v=')) {
        $errors[] = 'category bootstrap missing canonical carousel';
    }
}

$assetLoaderPath = $root . '/includes/liz-assistant-assets.php';
if (!is_file($assetLoaderPath)) {
    $errors[] = 'missing asset loader: includes/liz-assistant-assets.php';
} else {
    $assetLoader = (string) file_get_contents($assetLoaderPath);
    if (!str_contains($assetLoader, '/public/assets/liz-assistant/liz-identity-v2.js?v=')) {
        $errors[] = 'asset loader missing Liz identity v2';
    }
    if (str_contains($assetLoader, 'category-image-rotation-v2.js')) {
        $errors[] = 'asset loader still references duplicate category carousel';
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
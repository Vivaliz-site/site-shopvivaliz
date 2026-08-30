<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$feeds = [
    'shopping' => $root . '/google-shopping-feed.php',
    'merchant' => $root . '/google-merchant-feed.php',
];
$errors = [];
foreach ($feeds as $name => $file) {
    if (!is_file($file)) {
        $errors[] = "$name:missing_file";
        continue;
    }
    $xml = shell_exec('cd ' . escapeshellarg($root) . ' && php ' . escapeshellarg(basename($file)));
    if (!is_string($xml) || trim($xml) === '') {
        $errors[] = "$name:empty_output";
        continue;
    }
    $itemCount = substr_count($xml, '<item>');
    if ($itemCount < 100) {
        $errors[] = "$name:too_few_items:$itemCount";
    }
    if (strpos($xml, '<g:image_link>https://s3.amazonaws.com/tiny-anexos-') === false) {
        $errors[] = "$name:no_erp_tiny_images";
    }
    if (strpos($xml, '<g:price>') === false) {
        $errors[] = "$name:no_prices";
    }
    if (strpos($xml, '<g:availability>in_stock</g:availability>') === false) {
        $errors[] = "$name:no_in_stock_items";
    }
}
if ($errors !== []) {
    fwrite(STDERR, "GOOGLE_FEED_READINESS_FAILED\n" . implode("\n", array_unique($errors)) . "\n");
    exit(1);
}
echo "GOOGLE_FEED_READINESS_OK\n";

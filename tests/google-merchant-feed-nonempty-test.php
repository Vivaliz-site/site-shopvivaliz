<?php
declare(strict_types=1);
$base = getenv('SV_SITE_BASE_URL') ?: 'https://shopvivaliz.com.br';
$errors = [];
foreach (['google-shopping-feed.php', 'google-merchant-feed.php'] as $feed) {
    $url = rtrim($base, '/') . '/' . $feed . '?test=' . time();
    $xml = @file_get_contents($url);
    if (!is_string($xml) || $xml === '') {
        $errors[] = $feed . ':empty_response';
        continue;
    }
    $items = substr_count($xml, '<item>');
    if ($items < 100) {
        $errors[] = $feed . ':too_few_items:' . $items;
    }
    if (!str_contains($xml, '<g:image_link>https://s3.amazonaws.com/tiny-anexos-')) {
        $errors[] = $feed . ':missing_erp_tiny_s3_images';
    }
    if (str_contains($xml, 'placeholder') || str_contains($xml, '/uploads/catalog-fixed/')) {
        $errors[] = $feed . ':forbidden_placeholder_or_manual_image';
    }
}
if ($errors !== []) {
    fwrite(STDERR, "GOOGLE_MERCHANT_FEED_NONEMPTY_FAILED\n" . implode("\n", $errors) . "\n");
    exit(1);
}
echo "GOOGLE_MERCHANT_FEED_NONEMPTY_OK\n";

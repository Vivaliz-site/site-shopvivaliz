<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$feeds = [
    'google-merchant-feed.php' => 150,
    'google-shopping-feed.php' => 150,
];

foreach ($feeds as $feed => $minimumItems) {
    $cmd = 'cd ' . escapeshellarg($root) . ' && php ' . escapeshellarg($feed);
    $xml = shell_exec($cmd);
    if (!is_string($xml) || trim($xml) === '') {
        fwrite(STDERR, "$feed generated empty output\n");
        exit(1);
    }
    $count = substr_count($xml, '<item>');
    if ($count < $minimumItems) {
        fwrite(STDERR, "$feed item count too low: $count < $minimumItems\n");
        exit(1);
    }
}

echo "google-feeds-nonempty: ok\n";

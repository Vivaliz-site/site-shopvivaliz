<?php
declare(strict_types=1);

$path = dirname(__DIR__) . '/includes/integration-health.php';
$source = file_get_contents($path);
if (!is_string($source)) {
    fwrite(STDERR, "integration-health.php unreadable\n");
    exit(1);
}

$checks = [
    'canonical store helper' => str_contains($source, 'function svih_olist_token_store_path(): string'),
    'canonical store filename' => str_contains($source, "olist-tokens.json"),
    'explicit store override' => str_contains($source, "SHOPVIVALIZ_OLIST_TOKEN_FILE"),
    'legacy env token writer removed' => !str_contains($source, 'function svih_save_env_tokens('),
];

$start = strpos($source, 'function svih_olist(bool $fix): array');
$end = strpos($source, 'function svih_ml(bool $fix): array');
if ($start === false || $end === false || $end <= $start) {
    fwrite(STDERR, "Unable to isolate Olist monitor section\n");
    exit(1);
}
$olist = substr($source, $start, $end - $start);
$checks['Olist monitor cannot refresh'] = !str_contains($olist, "grant_type' => 'refresh_token'");
$checks['Olist monitor cannot call token endpoint'] = !str_contains($olist, '/protocol/openid-connect/token');
$checks['Olist monitor cannot persist env tokens'] = !str_contains($olist, 'svih_save_env_tokens');

$storedPos = strpos($olist, "(string)(\$stored['OLIST_ACCESS_TOKEN'] ?? '')");
$envPos = strpos($olist, "svih_env('OLIST_ACCESS_TOKEN')");
$checks['canonical access token precedes env fallback'] = $storedPos !== false && $envPos !== false && $storedPos < $envPos;

foreach ($checks as $name => $ok) {
    if (!$ok) {
        fwrite(STDERR, "FAIL: {$name}\n");
        exit(1);
    }
}
echo "Olist integration health read-only contract OK\n";

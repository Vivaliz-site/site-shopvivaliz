<?php
declare(strict_types=1);

$source = (string) file_get_contents(__DIR__ . '/../api/health/version.php');

function arHealthAssert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

arHealthAssert(str_contains($source, "'components' =>"), 'Version health must expose component health without removing the legacy top-level fields.');
arHealthAssert(str_contains($source, "'amazon_returns' => " . '$amazonReturns'), 'Version health must expose the Amazon Returns component.');
arHealthAssert(str_contains($source, "AMAZON_RETURNS_RELEASE_SHA_FILE"), 'Amazon Returns marker path must be overrideable for isolated tests and alternate deploy roots.');
arHealthAssert(str_contains($source, "amazon-returns-safet.service"), 'Amazon Returns service state must be included in component health.');
arHealthAssert(str_contains($source, "'service_state' => " . '$serviceState'), 'Amazon Returns health must report the service state explicitly.');

echo "amazon-returns-health-contract-test: OK\n";

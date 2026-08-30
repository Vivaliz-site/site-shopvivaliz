<?php
declare(strict_types=1);

$workflow = dirname(__DIR__) . '/.github/workflows/master-production-pipeline.yml';
$text = is_file($workflow) ? (string)file_get_contents($workflow) : '';

function mpmc_assert(bool $ok, string $message): void {
    if (!$ok) { fwrite(STDERR, "FAIL: {$message}\n"); exit(1); }
}

mpmc_assert($text !== '', 'master production workflow missing');
$monitor = strstr($text, '  monitor:');
mpmc_assert(is_string($monitor), 'monitor job missing');
mpmc_assert(str_contains($monitor, 'served_sha="$(ssh '), 'monitor must verify release sha via SSH');
$remoteMarker = "ubuntu@163.176.103.253 'bash -s' <<'REMOTE_MONITOR'";
mpmc_assert(str_contains($monitor, $remoteMarker), 'public smoke must execute from A1 through Cloudflare');
$remotePos = strpos($monitor, $remoteMarker);
$curlPos = strpos($monitor, 'code="$(curl -sS -o /tmp/body');
mpmc_assert($remotePos !== false && $curlPos !== false && $curlPos > $remotePos, 'storefront curl must be inside remote A1 block');
mpmc_assert(str_contains($monitor, 'https://shopvivaliz.com.br${path}'), 'monitor must still use public Cloudflare URL');
mpmc_assert(str_contains($monitor, "grep -q '<urlset'"), 'monitor must validate sitemap body');
mpmc_assert(str_contains($monitor, 'REMOTE_MONITOR'), 'remote monitor block must close explicitly');

echo "OK: master production monitor avoids GitHub-runner WAF false negatives\n";

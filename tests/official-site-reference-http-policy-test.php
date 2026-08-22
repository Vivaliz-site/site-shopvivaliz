<?php
declare(strict_types=1);

$policyPath = dirname(__DIR__) . '/scripts/quality/official-site-reference-http-policy.php';
if (!is_file($policyPath)) {
    fwrite(STDERR, "FAIL: official site HTTP status policy helper is missing\n");
    exit(1);
}
require_once $policyPath;

function osr_policy_assert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

osr_policy_assert(function_exists('sv_official_site_http_status_is_blocking'), 'status policy function must exist');
osr_policy_assert(sv_official_site_http_status_is_blocking(200) === false, 'HTTP 200 must pass');
osr_policy_assert(sv_official_site_http_status_is_blocking(301) === false, 'redirect must pass');
osr_policy_assert(sv_official_site_http_status_is_blocking(403) === false, 'edge/WAF 403 must be inconclusive, not a broken-route failure');
osr_policy_assert(sv_official_site_http_status_is_blocking(429) === false, 'rate limiting must be inconclusive, not a broken-route failure');
osr_policy_assert(sv_official_site_http_status_is_blocking(404) === true, 'HTTP 404 must fail');
osr_policy_assert(sv_official_site_http_status_is_blocking(410) === true, 'HTTP 410 must fail');
osr_policy_assert(sv_official_site_http_status_is_blocking(500) === true, 'HTTP 500 must fail');

fwrite(STDOUT, "official-site-reference-http-policy: ok\n");

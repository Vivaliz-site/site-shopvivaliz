<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/amazon-margin/RateLimitPolicy.php';

assert(SvAmazonMarginRateLimitPolicy::retryable(429));
assert(SvAmazonMarginRateLimitPolicy::retryable(503));
foreach ([200,201,400,401,403,404,409,422] as $code) {
    if (SvAmazonMarginRateLimitPolicy::retryable($code)) {
        throw new RuntimeException('unexpected retry on HTTP '.$code);
    }
}
$delays = SvAmazonMarginRateLimitPolicy::delays();
if ($delays !== [5,12,25,45,80]) throw new RuntimeException('retry schedule changed');
if (SvAmazonMarginRateLimitPolicy::totalWaitSeconds() !== 167) {
    throw new RuntimeException('unexpected total retry budget');
}
if (max($delays)>90) throw new RuntimeException('unbounded retry delay');
echo "PASS Amazon SP-API retry policy: HTTP 429/503, exponential bounded delays\n";

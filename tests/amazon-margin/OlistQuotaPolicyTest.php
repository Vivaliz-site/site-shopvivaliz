<?php
declare(strict_types=1);
require_once __DIR__.'/../../includes/amazon-margin/OlistQuotaPolicy.php';
$h=[];
foreach(['x-ratelimit-limit: 60','X-Ratelimit-Remaining: 4','x-ratelimit-reset: 27','Retry-After: 33','authorization: abc','x-ratelimit-remaining: anything'] as $line) {
    SvAmazonMarginOlistQuotaPolicy::header($line,$h);
}
if ($h['x-ratelimit-limit']!==60 || $h['x-ratelimit-remaining']!==4
    || $h['x-ratelimit-reset']!==27 || $h['retry-after']!==33 || count($h)!==4) {
    throw new RuntimeException('invalid quota header parsing');
}
if (SvAmazonMarginOlistQuotaPolicy::cooloffAfterSuccess($h)!==29) throw new RuntimeException('quota reserve missed');
if (SvAmazonMarginOlistQuotaPolicy::retryDelay(429,$h,0)!==35)throw new RuntimeException('retry-after not honored');
if (SvAmazonMarginOlistQuotaPolicy::retryDelay(429,['x-ratelimit-reset'=>45],1)!==47)throw new RuntimeException('quota reset missed');
if (SvAmazonMarginOlistQuotaPolicy::retryDelay(429,[],3)!==90)throw new RuntimeException('fallback delay incorrect');
if (SvAmazonMarginOlistQuotaPolicy::retryDelay(503,[],1)!==6)throw new RuntimeException('transient delay incorrect');
if (SvAmazonMarginOlistQuotaPolicy::cooloffAfterSuccess(['x-ratelimit-limit'=>60,'x-ratelimit-remaining'=>40,'x-ratelimit-reset'=>30])!==0) {
    throw new RuntimeException('should not delay on healthy quota');
}
foreach([0,429,500,502,503,504] as $status)if(!SvAmazonMarginOlistQuotaPolicy::retryable($status))throw new RuntimeException('missing retryable '.$status);
foreach([200,201,400,401,403,404,422] as $status)if(SvAmazonMarginOlistQuotaPolicy::retryable($status))throw new RuntimeException('unsafe retry '.$status);
echo "PASS Olist quota policy: shared limit parsing, reserve, Retry-After, 401/403 fail-closed\n";

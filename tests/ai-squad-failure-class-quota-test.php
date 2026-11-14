<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/includes/ai-squad-core.php';

foreach (['RESOURCE_EXHAUSTED', 'HTTP 429 Too Many Requests', 'provider http status 429'] as $message) {
    if (svais_failure_class(new RuntimeException($message)) !== 'quota') {
        fwrite(STDERR, 'FAIL: quota classification for ' . $message . PHP_EOL);
        exit(1);
    }
}
foreach (['timeout contacting provider' => 'timeout', 'provider HTTP 401 unauthorized' => 'auth', 'transport broken' => 'transport'] as $message => $expected) {
    if (svais_failure_class(new RuntimeException($message)) !== $expected) {
        fwrite(STDERR, 'FAIL: existing classification for ' . $message . PHP_EOL);
        exit(1);
    }
}
echo "SITE_QUOTA_FAILURE_CLASS=PASS\n";

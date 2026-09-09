<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$helper = $root . '/scripts/lib/google_search_console_retry.php';
if (!is_file($helper)) {
    fwrite(STDERR, "Missing GSC retry helper\n");
    exit(1);
}
require_once $helper;

$calls = 0;
$sleeps = [];
$responses = [
    ['status' => 500, 'body' => ['error' => ['message' => 'Internal error encountered.']], 'raw' => ''],
    ['status' => 200, 'body' => ['inspectionResult' => []], 'raw' => ''],
];
$response = gsc_url_inspection_request_with_retry(
    static function () use (&$calls, &$responses): array {
        $calls++;
        return array_shift($responses);
    },
    3,
    static function (int $microseconds) use (&$sleeps): void {
        $sleeps[] = $microseconds;
    }
);
if (($response['status'] ?? 0) !== 200 || $calls !== 2 || count($sleeps) !== 1) {
    fwrite(STDERR, "Transient 500 must be retried exactly once before success\n");
    exit(1);
}

$calls = 0;
$response = gsc_url_inspection_request_with_retry(
    static function () use (&$calls): array {
        $calls++;
        return ['status' => 400, 'body' => ['error' => ['message' => 'bad request']], 'raw' => ''];
    },
    3,
    static function (int $microseconds): void {}
);
if (($response['status'] ?? 0) !== 400 || $calls !== 1) {
    fwrite(STDERR, "Permanent 4xx errors must not be retried\n");
    exit(1);
}

fwrite(STDOUT, "google-search-console-transient-retry-test: ok\n");

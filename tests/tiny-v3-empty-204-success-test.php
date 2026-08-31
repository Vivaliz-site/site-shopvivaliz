<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/marketplace/TinyV3Runtime.php';

$cases = [
    [204, '', 204, []],
    [200, '', 200, []],
    [200, '{"ok":true}', 200, ['ok' => true]],
    [500, '{"message":"failure"}', 500, ['message' => 'failure']],
    [0, '', 0, []],
];

foreach ($cases as [$status, $body, $expectedStatus, $expectedJson]) {
    $parsed = sv_market_tiny_parse_response($status, $body);
    if ($parsed['status'] !== $expectedStatus || $parsed['json'] !== $expectedJson) {
        fwrite(STDERR, "response parse mismatch for status={$status}\n");
        exit(1);
    }
}

echo "tiny-v3-empty-204-success: ok\n";

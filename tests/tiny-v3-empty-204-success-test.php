<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/tiny-order-push.php';

$cases = [
    [204, '', false],
    [200, '', false],
    [200, '{"ok":true}', false],
    [500, '{"message":"failure"}', true],
    [0, '', true],
];

foreach ($cases as [$status, $body, $expected]) {
    $actual = svtop_tiny_response_needs_fallback($status, $body);
    if ($actual !== $expected) {
        fwrite(STDERR, "fallback mismatch for status={$status}\n");
        exit(1);
    }
}

echo "tiny-v3-empty-204-success: ok\n";

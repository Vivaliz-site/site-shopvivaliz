<?php
declare(strict_types=1);

/**
 * Disabled safety stub.
 *
 * The former implementation created a real production order by reusing data
 * from an existing customer. That behavior is intentionally unavailable.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}

fwrite(STDERR, "Live production order canary is disabled. Use an isolated test identity and provider sandbox.\n");
echo json_encode([
    'ok' => false,
    'error' => 'live_order_canary_disabled',
    'side_effects_attempted' => false,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
exit(2);

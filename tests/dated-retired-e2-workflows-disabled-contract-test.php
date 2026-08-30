<?php
$root = dirname(__DIR__);
$workflowDir = $root . '/.github/workflows';
$retired = ['137.131.156.17', '136.248.69.116', '10.0.1.13', '10.0.1.203', 'shopvivaliz-ai', 'shopvivaliz-micro-2'];
$violations = [];

foreach (glob($workflowDir . '/*.{yml,yaml}', GLOB_BRACE) ?: [] as $path) {
    $name = basename($path);
    if (!preg_match('/20[0-9]{6}|20[0-9]{2}-[0-9]{2}-[0-9]{2}|-[0-9]{10,}/', $name)) continue;
    $body = file_get_contents($path);
    foreach ($retired as $token) {
        if (strpos($body, $token) !== false) { $violations[] = $name; break; }
    }
}

if ($violations) {
    sort($violations);
    fwrite(STDERR, "Dated workflows targeting retired E2 infrastructure must be disabled:\n" . implode("\n", $violations) . "\n");
    exit(1);
}
echo "dated-retired-e2-workflows-disabled-contract: ok\n";

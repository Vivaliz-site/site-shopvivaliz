<?php
$root = dirname(__DIR__);
$workflowDir = $root . '/.github/workflows';
$retired = ['137.131.156.17', '136.248.69.116', '10.0.1.13', '10.0.1.203', 'shopvivaliz-ai', 'shopvivaliz-micro-2'];
$diagnosticName = '/^(audit-|inspect-|investigate-|probe-|diagnose-|vm-dc-.*once\.ya?ml$)/';
$violations = [];

foreach (glob($workflowDir . '/*.{yml,yaml}', GLOB_BRACE) ?: [] as $path) {
    $name = basename($path);
    if (!preg_match($diagnosticName, $name)) continue;
    $body = file_get_contents($path);
    foreach ($retired as $token) {
        if (strpos($body, $token) !== false) { $violations[] = $name; break; }
    }
}

if ($violations) {
    sort($violations);
    fwrite(STDERR, "Legacy diagnostic workflows targeting retired E2 infrastructure must be disabled:\n" . implode("\n", $violations) . "\n");
    exit(1);
}
echo "legacy-diagnostic-workflows-disabled-contract: ok\n";

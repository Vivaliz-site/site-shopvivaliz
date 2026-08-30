<?php
$root = dirname(__DIR__);
$workflowDir = $root . '/.github/workflows';
$activeTemporary = glob($workflowDir . '/tmp-*.yml') ?: [];
$activeTemporary = array_merge($activeTemporary, glob($workflowDir . '/tmp-*.yaml') ?: []);

if ($activeTemporary !== []) {
    $names = array_map('basename', $activeTemporary);
    sort($names);
    fwrite(STDERR, "Temporary workflows must not remain executable in .github/workflows:\n" . implode("\n", $names) . "\n");
    exit(1);
}

echo "temporary-workflows-disabled-contract: ok\n";

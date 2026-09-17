<?php
declare(strict_types=1);
$root = dirname(__DIR__);
$workflowDir = $root . '/.github/workflows';
$paths = glob($workflowDir . '/*.yml') ?: [];
$forbidden = ['trycloudflare.com'];
foreach ($paths as $path) {
    $text = (string) file_get_contents($path);
    foreach ($forbidden as $needle) {
        if (stripos($text, $needle) !== false) {
            fwrite(STDERR, basename($path) . " contains forbidden active public MCP route: {$needle}\n");
            exit(1);
        }
    }
}
$legacy = $workflowDir . '/remote-pc-tunnel-health.yml';
if (!is_file($legacy)) {
    fwrite(STDERR, "legacy compatibility workflow missing\n");
    exit(1);
}
$legacyText = (string) file_get_contents($legacy);
foreach (['remote-control-plane-health.yml', '127.0.0.1:5557', '127.0.0.1:5558'] as $needle) {
    if (strpos($legacyText, $needle) === false) {
        fwrite(STDERR, "compatibility workflow missing private control-plane marker: {$needle}\n");
        exit(1);
    }
}
echo "remote-control-plane-no-public-mcp-contract: ok\n";

<?php
$root = dirname(__DIR__);
$workflowDir = $root . '/.github/workflows';
$legacyVm = '137.131.156.17';
$productionPath = '/home/ubuntu/shopvivaliz-deploy';
$allowedLegacyControl = [
    'desktop-commander-24h-health.yml',
    'desktop-commander-three-host-control-plane.yml',
    'desktop-commander-three-host-quick-probe.yml',
    'vm-desktop-commander-action.yml',
    'vm-desktop-commander-connection-probe.yml',
    'vm-desktop-commander-secure-recovery.yml',
    'fix-old-vm-mei-apache-20260825.yml',
    'fix-old-vm-mei-apache-http01-20260825.yml',
    'pr-conflict-auto-healer.yml',
    'audit-openai-secondary-vm.yml',
];
$violations = [];
foreach (glob($workflowDir . '/*.yml') as $path) {
    $name = basename($path);
    $lines = file($path, FILE_IGNORE_NEW_LINES);
    $body = implode("\n", $lines);
    if (strpos($body, $productionPath) === false) continue;
    if (in_array($name, $allowedLegacyControl, true)) continue;
    if (str_starts_with($name, 'tmp-fredwin-') || str_starts_with($name, 'tmp-terminal-')) continue;
    foreach ($lines as $line) {
        if (str_starts_with(ltrim($line), '#')) continue;
        if (strpos($line, $legacyVm) !== false) { $violations[] = $name; break; }
    }
}
if ($violations) { fwrite(STDERR, "Production workflows still target VM1:\n" . implode("\n", $violations) . "\n"); exit(1); }
echo "production-workflows-vm2-contract: ok\n";

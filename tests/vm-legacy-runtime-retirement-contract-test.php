<?php
$root = dirname(__DIR__);
$scriptPath = $root . '/scripts/retire-vm-legacy-runtime.sh';
$workflowPath = $root . '/.github/workflows/vm-legacy-runtime-cleanup.yml';
$errors = [];

if (!is_file($scriptPath)) {
    $errors[] = 'retirement script missing';
} else {
    $script = (string) file_get_contents($scriptPath);
    $retired = [
        'desktop-commander.service',
        'shopvivaliz-mcp.service',
        'shopvivaliz-monitor.service',
        'shopvivaliz-sync.service',
        'shopvivaliz-24x7.service',
        'shopvivaliz-24x7.timer',
        'shopvivaliz-auto-sync.service',
        'shopvivaliz-auto-sync.timer',
        'shopvivaliz-git-sync.service',
        'shopvivaliz-git-sync.timer',
    ];
    foreach ($retired as $unit) {
        if (!str_contains($script, $unit)) $errors[] = "retired unit missing: {$unit}";
    }
    foreach ([
        'shopvivaliz-desktop-commander.service',
        'shopvivaliz-agent.service',
        'shopvivaliz-queue-worker.service',
        'shopvivaliz-token-renewer.service',
        'shopvivaliz-shopee-token-renewer.service',
        'shopvivaliz-sync-safe.service',
        'shopvivaliz-agent-bridge.service',
        'shopvivaliz-catalog-audit.service',
        'shopvivaliz-orchestrator.service',
        'shopvivaliz-products-active-sync.service',
    ] as $unit) {
        if (!str_contains($script, "PRESERVE_UNIT='{$unit}'") && !str_contains($script, "'{$unit}'")) {
            $errors[] = "preserved unit verification missing: {$unit}";
        }
    }
    if (!str_contains($script, 'systemctl daemon-reload')) $errors[] = 'daemon-reload missing';
    if (!str_contains($script, 'shopvivaliz-shopee-token-renewer.service.pre-hardening-20260803')) $errors[] = 'stale Shopee backup cleanup missing';
    if (!str_contains($script, 'shopvivaliz-shopee-token-renewer.service.repair-20260808T160822Z.bak')) $errors[] = 'Shopee repair backup cleanup missing';
}

if (!is_file($workflowPath)) {
    $errors[] = 'cleanup workflow missing';
} else {
    $workflow = (string) file_get_contents($workflowPath);
    foreach (['StrictHostKeyChecking=yes', 'git restore --source=origin/main -- scripts/retire-vm-legacy-runtime.sh', 'sudo bash scripts/retire-vm-legacy-runtime.sh'] as $needle) {
        if (!str_contains($workflow, $needle)) $errors[] = "workflow missing: {$needle}";
    }
    if (str_contains($workflow, 'git reset --hard') || str_contains($workflow, 'git merge --ff-only')) {
        $errors[] = 'workflow contains broad/destructive repository mutation';
    }
}

if ($errors) {
    fwrite(STDERR, implode(PHP_EOL, $errors) . PHP_EOL);
    exit(1);
}

echo "vm-legacy-runtime-retirement-contract: ok\n";

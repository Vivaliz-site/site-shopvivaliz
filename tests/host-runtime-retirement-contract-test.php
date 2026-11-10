<?php
$errors = [];
$installer = (string) @file_get_contents(__DIR__ . '/../scripts/install-vm-desktop-commander-service.sh');
$fredWorkflow = (string) @file_get_contents(__DIR__ . '/../.github/workflows/fred-win-remote-action.yml');

if ($installer === '') {
    $errors[] = 'VM Desktop Commander installer missing';
} else {
    if (!str_contains($installer, "LEGACY_UNIT_TARGET='/etc/systemd/system/desktop-commander.service'")) {
        $errors[] = 'installer must name the legacy VM unit file explicitly';
    }
    if (!preg_match('/rm\s+-f\s+"?\$LEGACY_UNIT_TARGET"?/', $installer)) {
        $errors[] = 'installer must remove the legacy VM unit file';
    }
    if (!str_contains($installer, 'systemctl daemon-reload')) {
        $errors[] = 'installer must reload systemd after retirement';
    }
    if (!str_contains($installer, "if systemctl cat \"\$LEGACY_SERVICE\" >/dev/null 2>&1; then")) {
        $errors[] = 'installer must verify the legacy service is absent';
    }
}

if ($fredWorkflow === '') {
    $errors[] = 'Fred-Win remote workflow missing';
} else {
    if (str_contains($fredWorkflow, 'install_mcp_startup)')) {
        $errors[] = 'legacy install_mcp_startup action must be removed';
    }
    if (str_contains($fredWorkflow, 'ShopVivaliz FredWin MCP Startup')) {
        $errors[] = 'legacy Fred-Win MCP startup task creation must be removed';
    }
    if (str_contains($fredWorkflow, 'iniciar-fredwin-mcp.bat')) {
        $errors[] = 'legacy Fred-Win MCP startup BAT creation must be removed';
    }
}

if ($errors) {
    fwrite(STDERR, implode(PHP_EOL, $errors) . PHP_EOL);
    exit(1);
}

echo "host-runtime-retirement-contract: ok\n";

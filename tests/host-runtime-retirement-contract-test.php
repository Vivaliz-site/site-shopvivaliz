<?php
$errors = [];
$fredWorkflow = (string) @file_get_contents(__DIR__ . '/../.github/workflows/fred-win-remote-action.yml');

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

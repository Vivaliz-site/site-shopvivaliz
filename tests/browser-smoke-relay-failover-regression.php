<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$selector = (string) file_get_contents($root . '/scripts/select-active-products-browser-relay.sh');
foreach (['probe_relay 5557 fred-win', 'probe_relay 5558 desktop-kocepsv', 'No healthy allowlisted Windows browser relay is available'] as $needle) {
    if (!str_contains($selector, $needle)) {
        fwrite(STDERR, "relay selector missing: {$needle}\n");
        exit(1);
    }
}

$workflows = [
    '.github/workflows/fred-win-admin-mobile-readonly-smoke.yml',
    '.github/workflows/active-products-browser-smoke.yml',
    '.github/workflows/image-run-browser-smoke.yml',
];
foreach ($workflows as $relative) {
    $text = (string) file_get_contents($root . '/' . $relative);
    $checks = [
        'shared relay selector' => 'scripts/select-active-products-browser-relay.sh',
        'selected relay environment' => 'SV_BROWSER_RELAY_PORT',
        'validated selected port' => "port not in {'5557','5558'}",
    ];
    foreach ($checks as $label => $needle) {
        if (!str_contains($text, $needle)) {
            fwrite(STDERR, "$relative missing $label\n");
            exit(1);
        }
    }
    if (!str_contains($text, 'RELAY_PORT=\'$SV_BROWSER_RELAY_PORT\'')
        && !str_contains($text, 'RELAY_PORT="$SV_BROWSER_RELAY_PORT"')) {
        fwrite(STDERR, "$relative missing selected relay propagation\n");
        exit(1);
    }
    if (str_contains($text, 'http://127.0.0.1:5557/mcp/tool/execute_command')) {
        fwrite(STDERR, "$relative still hardcodes Fred relay for browser execution\n");
        exit(1);
    }
}

$activeProductsWorkflow = (string) file_get_contents($root . '/.github/workflows/active-products-browser-smoke.yml');
if (!str_contains($activeProductsWorkflow, "workflow_run:")
    || !str_contains($activeProductsWorkflow, "workflows: ['Master Production Pipeline 24/7']")
    || !str_contains($activeProductsWorkflow, 'github.event_name == \'workflow_dispatch\'')
    || !str_contains($activeProductsWorkflow, 'inputs.target_sha')
    || !str_contains($activeProductsWorkflow, 'github.event.workflow_run.head_sha')) {
    fwrite(STDERR, "active-products-browser-smoke must support both production workflow completion and explicit SHA dispatch\n");
    exit(1);
}
fwrite(STDOUT, "browser-smoke-relay-failover: ok\n");

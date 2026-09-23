<?php
declare(strict_types=1);

$api = (string)file_get_contents(dirname(__DIR__) . '/api/agent/buscador.php');

$required = [
    "'BUSCADOR_MCP_KEY'",
    "'GEPETO_ACTION_KEY'",
    "'SHOPVIVALIZ_AGENT_KEY'",
];

foreach ($required as $needle) {
    if (!str_contains($api, $needle)) {
        fwrite(STDERR, "missing auth candidate: {$needle}\n");
        exit(1);
    }
}

if (!str_contains($api, 'hash_equals($expected, $provided)')) {
    fwrite(STDERR, "constant-time key comparison missing\n");
    exit(1);
}

if (preg_match('/BUSCADOR_MCP_KEY\s*=\s*[\'\"][^\'\"]+[\'\"]/', $api)) {
    fwrite(STDERR, "BUSCADOR_MCP_KEY must never be hardcoded\n");
    exit(1);
}

echo "BUSCADOR_MCP_AUTH_CONTRACT=PASS\n";

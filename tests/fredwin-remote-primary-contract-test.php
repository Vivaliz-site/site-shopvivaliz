<?php
declare(strict_types=1);
$root = dirname(__DIR__);
$workflow = $root . '/.github/workflows/fred-win-remote-action.yml';
$request = $root . '/ops/fredwin-request.json';
foreach ([$workflow, $request] as $path) {
    if (!is_file($path)) { fwrite(STDERR, "missing {$path}\n"); exit(1); }
}
$yml = (string) file_get_contents($workflow);
$required = [
    'ops/fredwin-request.json',
    'runs-on: [self-hosted, Linux, ARM64, shopvivaliz-a1-deploy]',
    'health)',
    'runtime_identity)',
    'http://127.0.0.1:5557/health',
    'http://127.0.0.1:5557/mcp/tool/execute_command',
    'environment=fred-win',
    'COMPUTERNAME',
    'Action not allowlisted',
];
foreach ($required as $needle) {
    if (strpos($yml, $needle) === false) { fwrite(STDERR, "missing {$needle}\n"); exit(1); }
}
$forbidden = [
    'ubuntu@10.0.1.38',
    'configure_desktop_commander_allow_all',
    'desktop-commander remote',
    'device.json',
    'access_token',
    'refresh_token',
    'command)',
];
foreach ($forbidden as $needle) {
    if (stripos($yml, $needle) !== false) { fwrite(STDERR, "forbidden {$needle}\n"); exit(1); }
}
$requestJson = json_decode((string) file_get_contents($request), true);
if (!is_array($requestJson) || ($requestJson['action'] ?? null) !== 'health') {
    fwrite(STDERR, "fredwin default request must be health\n");
    exit(1);
}
echo "fredwin-remote-primary-contract: ok\n";

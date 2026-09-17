<?php
declare(strict_types=1);
$root = dirname(__DIR__);
$workflow = $root . '/.github/workflows/desktopkocepsv-remote-action.yml';
$request = $root . '/ops/desktopkocepsv-remote-request.json';
foreach ([$workflow, $request] as $path) {
    if (!is_file($path)) { fwrite(STDERR, "missing {$path}\n"); exit(1); }
}
$yml = (string) file_get_contents($workflow);
$required = [
    'ops/desktopkocepsv-remote-request.json',
    'health)',
    'runtime_identity)',
    'http://127.0.0.1:5558/health',
    'http://127.0.0.1:5558/mcp/tool/execute_command',
    'COMPUTERNAME',
    'whoami',
    'Action not allowlisted',
    'StrictHostKeyChecking=yes',
];
foreach ($required as $needle) {
    if (strpos($yml, $needle) === false) { fwrite(STDERR, "missing {$needle}\n"); exit(1); }
}
$forbidden = ['access_token','refresh_token','auth_token','device code','verification_uri','trycloudflare.com','inputs:\n      command:'];
foreach ($forbidden as $needle) {
    if (stripos($yml, $needle) !== false) { fwrite(STDERR, "forbidden {$needle}\n"); exit(1); }
}
$requestJson = json_decode((string) file_get_contents($request), true);
if (!is_array($requestJson) || ($requestJson['action'] ?? null) !== 'health') {
    fwrite(STDERR, "default request must be health\n");
    exit(1);
}
echo "desktopkocepsv-remote-action-contract: ok\n";

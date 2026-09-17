<?php
$root = dirname(__DIR__);
$workflowPath = $root . '/.github/workflows/remote-host-action.yml';
$requestPath = $root . '/ops/remote-host-request.json';
foreach ([$workflowPath, $requestPath] as $path) {
    if (!is_file($path)) { fwrite(STDERR, "missing $path\n"); exit(1); }
}
$workflow = file_get_contents($workflowPath);
$request = json_decode(file_get_contents($requestPath), true);
$required = [
    'shopvivaliz-free-a1',
    'always-free-arm-1787907847-26',
    'identity)',
    'repo_status)',
    'Action not allowlisted',
    'Target not allowlisted',
    'shopvivaliz-a1-deploy',
    'ubuntu@10.0.1.38',
    'StrictHostKeyChecking=yes',
    '/home/ubuntu/shopvivaliz-deploy/repo',
    'git branch --show-current',
    'git status --porcelain',
    'git rev-parse HEAD',
];
foreach ($required as $needle) {
    if (strpos($workflow, $needle) === false) { fwrite(STDERR, "missing $needle\n"); exit(1); }
}
$forbidden = [
    '137.131.149.55',
    'git reset --hard',
    '/home/ubuntu/shopvivaliz-deploy/current',
    'echo ${{ secrets.',
    'echo "${{ secrets.',
    'inputs.command',
    'command:',
];
foreach ($forbidden as $needle) {
    if (stripos($workflow, $needle) !== false) { fwrite(STDERR, "forbidden $needle\n"); exit(1); }
}
if (($request['target'] ?? null) !== 'shopvivaliz-free-a1' || ($request['action'] ?? null) !== 'identity') {
    fwrite(STDERR, "invalid default request\n"); exit(1);
}
if (substr_count($workflow, 'shopvivaliz-free-a1') < 1 || substr_count($workflow, 'always-free-arm-1787907847-26') < 1) {
    fwrite(STDERR, "target allowlist incomplete\n"); exit(1);
}
echo "remote-host-action-contract: ok\n";
